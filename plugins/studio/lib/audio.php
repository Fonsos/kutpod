<?php
// ============================================================================
// KutPod · Estudio · audio: copias de trabajo WAV, envolvente y sincronización
// ============================================================================
if (!defined('KUTPOD_VERSION')) { exit; }

// ── WAV (PCM s16le mono) ────────────────────────────────────────────────────

/** Lee la cabecera RIFF y devuelve [offset de datos, bytes de datos, frecuencia] */
function studio_wav_info(string $path): ?array {
  $fh = @fopen($path, 'rb');
  if (!$fh) return null;
  $h = fread($fh, 12);
  if (strlen($h) < 12 || substr($h, 0, 4) !== 'RIFF') { fclose($fh); return null; }
  $sr = 0; $off = 12;
  while (!feof($fh)) {
    $ch = fread($fh, 8);
    if (strlen($ch) < 8) break;
    $id = substr($ch, 0, 4); $len = unpack('V', substr($ch, 4, 4))[1];
    $off += 8;
    if ($id === 'fmt ') {
      $f = fread($fh, $len);
      $sr = unpack('V', substr($f, 4, 4))[1];
    } elseif ($id === 'data') {
      fclose($fh);
      $size = filesize($path);
      if ($len === 0xFFFFFFFF || $off + $len > $size) $len = $size - $off;
      return ['off' => $off, 'bytes' => $len, 'sr' => $sr ?: STUDIO_SR];
    } else {
      fseek($fh, $len + ($len & 1), SEEK_CUR);
    }
    $off += $len + ($len & 1);
  }
  fclose($fh);
  return null;
}

function studio_wav_header(int $dataBytes, int $sr = STUDIO_SR): string {
  return 'RIFF' . pack('V', 36 + $dataBytes) . 'WAVE'
       . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $sr, $sr * 2, 2, 16)
       . 'data' . pack('V', $dataBytes);
}

/**
 * Copia $count muestras desde $startSample de un WAV abierto a $out.
 * Fuera del rango del archivo escribe silencio. Aplica fundidos lineales (muestras).
 */
function studio_wav_copy($in, array $info, $out, int $startSample, int $count, int $fadeIn = 0, int $fadeOut = 0): void {
  $total = intdiv($info['bytes'], 2);
  $written = 0;
  while ($written < $count) {
    $n = min(262144, $count - $written);
    $pos = $startSample + $written;
    $buf = '';
    if ($pos + $n <= 0 || $pos >= $total) {
      $buf = str_repeat("\0\0", $n);
    } else {
      $lead = $pos < 0 ? -$pos : 0;
      $avail = max(0, min($n - $lead, $total - max($pos, 0)));
      if ($lead) $buf .= str_repeat("\0\0", $lead);
      if ($avail > 0) {
        fseek($in, $info['off'] + max($pos, 0) * 2);
        $d = fread($in, $avail * 2);
        $buf .= $d;
        $avail = intdiv(strlen($d), 2);
      }
      $buf = str_pad($buf, $n * 2, "\0");
    }
    // Fundidos en los bordes de la pieza
    if ($fadeIn && $written < $fadeIn) {
      $m = min($n, $fadeIn - $written);
      $s = array_values(unpack('s*', substr($buf, 0, $m * 2)));
      for ($i = 0; $i < $m; $i++) $s[$i] = (int)round($s[$i] * (($written + $i) / $fadeIn));
      $buf = pack('s*', ...$s) . substr($buf, $m * 2);
    }
    if ($fadeOut && $written + $n > $count - $fadeOut) {
      $from = max(0, $count - $fadeOut - $written);
      $m = $n - $from;
      $s = array_values(unpack('s*', substr($buf, $from * 2, $m * 2)));
      for ($i = 0; $i < $m; $i++) {
        $k = $written + $from + $i;                    // posición en la pieza
        $s[$i] = (int)round($s[$i] * (($count - 1 - $k) / $fadeOut));
      }
      $buf = substr($buf, 0, $from * 2) . pack('s*', ...$s);
    }
    fwrite($out, $buf);
    $written += $n;
  }
}

// ── Ingesta de una pista ────────────────────────────────────────────────────

/** Convierte el audio original en copia de trabajo mono 44,1 kHz y calcula su envolvente. */
function studio_ingest_track(int $pid, string $tid, string $src, ?callable $onProgress = null): array {
  $dir  = studio_pdir($pid, 'tracks');
  $wav  = "$dir/$tid.wav";
  $info = studio_probe($src);
  if (!$info || $info['duration'] <= 0) throw new RuntimeException('No se puede leer el audio (¿formato no soportado?)');
  [$code, $err] = studio_ffmpeg(['-i', $src, '-vn', '-ac', '1', '-ar', (string)STUDIO_SR, '-c:a', 'pcm_s16le',
    '-fflags', '+bitexact', '-flags:a', '+bitexact', '-f', 'wav', $wav], $info['duration'], $onProgress ? fn($f) => $onProgress($f * 0.8) : null);
  if ($code !== 0) throw new RuntimeException('ffmpeg falló al convertir: ' . trim(substr($err, -400)));
  studio_env_build($wav, "$dir/$tid.env");
  if ($onProgress) $onProgress(1.0);
  $w = studio_wav_info($wav);
  return ['duration' => $w['bytes'] / 2 / $w['sr'], 'source_rate' => $info['sample_rate']];
}

// ── Envolvente de energía (10 ms, 1 byte por trama) ─────────────────────────

function studio_env_build(string $wav, string $out): void {
  $af = 'aresample=8000,asetnsamples=n=80:p=0,astats=metadata=1:reset=1,ametadata=mode=print:key=lavfi.astats.Overall.RMS_level:file=-';
  $bytes = '';
  $cmd = [studio_bin('ffmpeg'), '-hide_banner', '-nostdin', '-v', 'error', '-i', $wav, '-af', $af, '-f', 'null', '-'];
  [$code] = studio_run($cmd, function ($line) use (&$bytes) {
    if (str_starts_with($line, 'lavfi.astats.Overall.RMS_level=')) {
      $db = substr($line, 31);
      $db = ($db === '-inf' || !is_numeric($db)) ? -90.0 : max(-90.0, (float)$db);
      $bytes .= chr((int)min(255, round(($db + 90) * 2.8)));   // -90 dB → 0 ; 0 dB → 252
    }
  }, null, false);
  if ($code !== 0 || $bytes === '') throw new RuntimeException('No se pudo calcular la envolvente de audio');
  file_put_contents($out, $bytes);
}

function studio_env_read(string $f): array {
  if (!is_file($f)) return [];
  return array_map(fn($b) => $b / 2.8 - 90, array_values(unpack('C*', file_get_contents($f))));
}

/** Envolvente (dB, trama de 10 ms) de la copia de trabajo ACTUAL (ya alineada) de la pista. */
function studio_env_load(int $pid, string $tid): array {
  return studio_env_read(studio_pdir($pid, 'tracks') . "/$tid.env");
}

/**
 * Rutas de una pista. Si se recortó para sincronizarla, el original se guarda como *.raw.*
 * y *.wav/*.env pasan a ser la versión alineada con la referencia.
 */
function studio_track_paths(int $pid, string $tid): array {
  $d = studio_pdir($pid, 'tracks');
  $has = is_file("$d/$tid.raw.wav");
  return [
    'wav' => "$d/$tid.wav", 'env' => "$d/$tid.env",
    'raw_wav_path' => "$d/$tid.raw.wav", 'raw_env_path' => "$d/$tid.raw.env",
    'has_raw' => $has,
    'src_wav' => $has ? "$d/$tid.raw.wav" : "$d/$tid.wav",   // audio original de la pista
    'src_env' => $has ? "$d/$tid.raw.env" : "$d/$tid.env",
  ];
}

// ── Sincronización por correlación cruzada de envolventes ───────────────────

function studio_fft(array &$re, array &$im, bool $inverse = false): void {
  $n = count($re);
  for ($i = 1, $j = 0; $i < $n; $i++) {
    $bit = $n >> 1;
    for (; $j & $bit; $bit >>= 1) $j ^= $bit;
    $j ^= $bit;
    if ($i < $j) { [$re[$i], $re[$j]] = [$re[$j], $re[$i]]; [$im[$i], $im[$j]] = [$im[$j], $im[$i]]; }
  }
  for ($len = 2; $len <= $n; $len <<= 1) {
    $ang = 2 * M_PI / $len * ($inverse ? -1 : 1);
    $half = $len >> 1;
    $wr = []; $wi = [];
    for ($k = 0; $k < $half; $k++) { $wr[$k] = cos($ang * $k); $wi[$k] = -sin($ang * $k); }
    for ($i = 0; $i < $n; $i += $len) {
      for ($k = 0; $k < $half; $k++) {
        $a = $i + $k; $b = $a + $half;
        $tr = $re[$b] * $wr[$k] - $im[$b] * $wi[$k];
        $ti = $re[$b] * $wi[$k] + $im[$b] * $wr[$k];
        $re[$b] = $re[$a] - $tr; $im[$b] = $im[$a] - $ti;
        $re[$a] += $tr;          $im[$a] += $ti;
      }
    }
  }
  if ($inverse) for ($i = 0; $i < $n; $i++) { $re[$i] /= $n; $im[$i] /= $n; }
}

/** Reduce la envolvente a $rate Hz (media) y la deja con media 0 y desviación 1. */
function studio_env_feature(array $env, int $step): array {
  $out = []; $n = count($env);
  for ($i = 0; $i + $step <= $n; $i += $step) {
    $s = 0.0;
    for ($k = 0; $k < $step; $k++) $s += max(-70.0, min(-10.0, $env[$i + $k]));
    $out[] = $s / $step;
  }
  $m = count($out) ? array_sum($out) / count($out) : 0;
  $v = 0.0; foreach ($out as $x) $v += ($x - $m) ** 2;
  $sd = sqrt($v / max(1, count($out))) ?: 1.0;
  foreach ($out as &$x) $x = ($x - $m) / $sd;
  return $out;
}

/**
 * Calcula dónde empieza $b en la línea de tiempo de $a.
 * Devuelve ['offset' => segundos (b[t] ≈ a[t+offset]), 'confidence' => z-score del pico].
 */
function studio_xcorr(array $envA, array $envB): array {
  $step = 4;                                           // 25 Hz para la búsqueda gruesa
  $a = studio_env_feature($envA, $step);
  $b = studio_env_feature($envB, $step);
  if (count($a) < 50 || count($b) < 50) return ['offset' => 0.0, 'confidence' => 0.0];
  $n = 1; while ($n < count($a) + count($b)) $n <<= 1;
  $ar = array_pad($a, $n, 0.0); $ai = array_fill(0, $n, 0.0);
  $br = array_pad($b, $n, 0.0); $bi = array_fill(0, $n, 0.0);
  studio_fft($ar, $ai); studio_fft($br, $bi);
  for ($i = 0; $i < $n; $i++) {                        // A · conj(B)
    $r = $ar[$i] * $br[$i] + $ai[$i] * $bi[$i];
    $im = $ai[$i] * $br[$i] - $ar[$i] * $bi[$i];
    $ar[$i] = $r; $ai[$i] = $im;
  }
  studio_fft($ar, $ai, true);
  // c[k] = Σ a[t+k]·b[t]; k<0 está al final del vector
  $best = -INF; $bk = 0; $sum = 0.0; $sum2 = 0.0; $cnt = 0;
  $lagMin = -(count($b) - 1); $lagMax = count($a) - 1;
  $minOverlap = max(100, (int)(0.3 * min(count($a), count($b))));   // los extremos (poco solape) dan picos falsos
  for ($k = $lagMin; $k <= $lagMax; $k++) {
    $idx = $k >= 0 ? $k : $n + $k;
    $c = $ar[$idx];
    // solapamiento mínimo: ignora ajustes con poco audio común
    $overlap = min(count($a), count($b) + $k) - max(0, $k);
    if ($overlap < $minOverlap) continue;
    $c /= $overlap;
    $sum += $c; $sum2 += $c * $c; $cnt++;
    if ($c > $best) { $best = $c; $bk = $k; }
  }
  $mean = $sum / max(1, $cnt);
  $sd = sqrt(max(1e-12, $sum2 / max(1, $cnt) - $mean * $mean));
  $conf = ($best - $mean) / $sd;

  // Ajuste fino a 100 Hz (±80 ms) sobre la zona de solape
  $off = $bk * $step / 100.0;
  $fine = studio_refine($envA, $envB, $off);
  return ['offset' => $fine, 'confidence' => round($conf, 1)];
}

function studio_refine(array $envA, array $envB, float $offset): float {
  $o0 = (int)round($offset * 100);
  $best = -INF; $bo = $o0;
  $na = count($envA); $nb = count($envB);
  for ($o = $o0 - 8; $o <= $o0 + 8; $o++) {
    $from = max(0, -$o); $to = min($nb, $na - $o);
    if ($to - $from < 200) continue;
    if ($to - $from > 60000) { $mid = intdiv($from + $to, 2); $from = $mid - 30000; $to = $mid + 30000; }
    $sa = 0.0; $sb = 0.0; $sab = 0.0; $saa = 0.0; $sbb = 0.0; $m = $to - $from;
    for ($t = $from; $t < $to; $t++) {
      $x = $envA[$t + $o]; $y = $envB[$t];
      $sa += $x; $sb += $y; $sab += $x * $y; $saa += $x * $x; $sbb += $y * $y;
    }
    $den = sqrt(max(1e-9, ($saa - $sa * $sa / $m) * ($sbb - $sb * $sb / $m)));
    $c = ($sab - $sa * $sb / $m) / $den;
    if ($c > $best) { $best = $c; $bo = $o; }
  }
  return $bo / 100.0;
}

// ── Sincronización por tramos (fallos de grabación de la referencia) ───────
//
// Si la referencia (p. ej. la P4) pierde audio o inserta silencio a mitad de grabación, el desfase
// entre ambas pistas cambia de golpe. Se detecta comparando por ventanas, se localiza el punto exacto
// y se reconstruye la pista cortando lo que sobra o insertando silencio, para que quede alineada
// con la referencia de principio a fin.
//
// Convención: pista[t] ≈ referencia[t + offset]  →  t_ref = t_pista + offset.

/** Correlación de Pearson entre la referencia en [t0,t1] y la pista desplazada $off (envolventes a 100 Hz). */
function studio_ncc100(array $envA, array $envB, float $t0, float $t1, float $off): float {
  $shift = (int)round($off * 100);
  $i0 = max((int)round($t0 * 100), $shift, 0);
  $i1 = min((int)round($t1 * 100), count($envA), count($envB) + $shift);
  $n = $i1 - $i0;
  if ($n < 100) return 0.0;
  $sa = $sb = $saa = $sbb = $sab = 0.0;
  for ($i = $i0; $i < $i1; $i++) {
    $x = $envA[$i]; $y = $envB[$i - $shift];
    $sa += $x; $sb += $y; $saa += $x * $x; $sbb += $y * $y; $sab += $x * $y;
  }
  $va = $saa - $sa * $sa / $n; $vb = $sbb - $sb * $sb / $n;
  if ($va < 1e-6 || $vb < 1e-6) return 0.0;
  return ($sab - $sa * $sb / $n) / sqrt($va * $vb);
}

/** Desfase local por ventanas (resolución de 40 ms) alrededor del desfase global $o0. */
function studio_window_offsets(array $envA, array $envB, float $o0, float $maxDrift = 20.0, float $winSec = 30.0, float $hopSec = 10.0): array {
  $dt = 0.04;
  $fa = studio_env_feature($envA, 4); $fb = studio_env_feature($envB, 4);
  $n = (int)round($winSec / $dt); $hop = (int)round($hopSec / $dt);
  $kc = (int)round($o0 / $dt); $D = (int)round($maxDrift / $dt);
  $kmax = $kc + $D;
  $A = count($fa); $B = count($fb);
  $m = $n + 2 * $D;
  $N = 1; while ($N < $m + $n) $N <<= 1;
  $out = [];
  for ($a0 = 0; $a0 + $n <= $A; $a0 += $hop) {
    $x = array_slice($fa, $a0, $n);
    $mx = array_sum($x) / $n; $vx = 0.0;
    foreach ($x as $v) $vx += ($v - $mx) ** 2;
    $sx = sqrt($vx / $n);
    if ($sx < 0.35) continue;                                   // ventana casi en silencio: no informa
    foreach ($x as &$v) $v = ($v - $mx) / $sx;
    unset($v);
    // y = pista en [a0-kmax, a0-kmax+m); fuera de la pista, ceros (esos desplazamientos no se evalúan)
    $y0 = $a0 - $kmax; $y = [];
    for ($i = 0; $i < $m; $i++) { $j = $y0 + $i; $y[$i] = ($j >= 0 && $j < $B) ? $fb[$j] : 0.0; }
    $sLo = max(0, -$y0); $sHi = min(2 * $D, $B - $y0 - $n);      // desplazamientos con la ventana entera dentro de la pista
    if ($sHi - $sLo < $D / 2) continue;
    // correlación r(s) = Σ x[t]·y[s+t]  vía FFT
    $xr = array_pad($x, $N, 0.0); $xi = array_fill(0, $N, 0.0);
    $yr = array_pad($y, $N, 0.0); $yi = array_fill(0, $N, 0.0);
    studio_fft($xr, $xi); studio_fft($yr, $yi);
    for ($i = 0; $i < $N; $i++) {                                // conj(X)·Y
      $re = $xr[$i] * $yr[$i] + $xi[$i] * $yi[$i];
      $im = $xr[$i] * $yi[$i] - $xi[$i] * $yr[$i];
      $xr[$i] = $re; $xi[$i] = $im;
    }
    studio_fft($xr, $xi, true);
    // desviación local de y (prefijos) para normalizar
    $p1 = [0.0]; $p2 = [0.0];
    foreach ($y as $i => $v) { $p1[$i + 1] = $p1[$i] + $v; $p2[$i + 1] = $p2[$i] + $v * $v; }
    $best = -INF; $bs = 0; $sum = 0.0; $sum2 = 0.0; $cnt = 0;
    for ($s = $sLo; $s <= $sHi; $s++) {
      $my = ($p1[$s + $n] - $p1[$s]) / $n;
      $vy = ($p2[$s + $n] - $p2[$s]) / $n - $my * $my;
      $c = $vy > 1e-6 ? $xr[$s] / ($n * sqrt($vy)) : 0.0;
      $sum += $c; $sum2 += $c * $c; $cnt++;
      if ($c > $best) { $best = $c; $bs = $s; }
    }
    $mean = $sum / $cnt; $sd = sqrt(max(1e-12, $sum2 / $cnt - $mean * $mean));
    $out[] = ['t' => $a0 * $dt, 'off' => ($kmax - $bs) * $dt, 'ncc' => $best, 'conf' => ($best - $mean) / $sd];
  }
  return $out;
}

/** Agrupa ventanas consecutivas con el mismo desfase. Devuelve [['off','t0','t1','n'],…]. */
function studio_group_offsets(array $w, float $winSec, float $tol = 0.12): array {
  $good = array_values(array_filter($w, fn($x) => $x['ncc'] >= 0.5 && $x['conf'] >= 3.5));
  if (count($good) < 3) return [];
  $med = function (array $l) { sort($l); return $l[intdiv(count($l), 2)]; };
  $groups = []; $cur = [$good[0]];
  for ($i = 1; $i < count($good); $i++) {
    $recent = array_slice(array_column($cur, 'off'), -5);
    if (abs($good[$i]['off'] - $med($recent)) <= $tol) { $cur[] = $good[$i]; continue; }
    // ¿cambio sostenido? (la siguiente ventana confirma el nuevo desfase) o valor aislado (se descarta)
    if ($i + 1 < count($good) && abs($good[$i + 1]['off'] - $good[$i]['off']) <= $tol) { $groups[] = $cur; $cur = [$good[$i]]; }
  }
  $groups[] = $cur;
  $out = [];
  foreach ($groups as $g) {
    if (count($g) < 2 && count($groups) > 1) continue;                       // grupo diminuto: ruido
    $off = $med(array_column($g, 'off'));
    if ($out && abs($out[count($out) - 1]['off'] - $off) <= $tol) {         // vecinos iguales → unir
      $out[count($out) - 1]['t1'] = end($g)['t'] + $winSec; $out[count($out) - 1]['n'] += count($g);
      continue;
    }
    $out[] = ['off' => $off, 't0' => $g[0]['t'], 't1' => end($g)['t'] + $winSec, 'n' => count($g)];
  }
  return $out;
}

/** Afina un desfase a 100 Hz (±0,1 s) sobre el tramo [t0,t1] de la referencia. */
function studio_refine_range(array $envA, array $envB, float $off, float $t0, float $t1): float {
  $mid = ($t0 + $t1) / 2; $half = min(($t1 - $t0) / 2, 30.0);
  $best = -INF; $bo = $off;
  for ($o = $off - 0.1; $o <= $off + 0.1001; $o += 0.01) {
    $c = studio_ncc100($envA, $envB, $mid - $half, $mid + $half, $o);
    if ($c > $best) { $best = $c; $bo = $o; }
  }
  return round($bo, 3);
}

/**
 * Analiza la pista contra la referencia y devuelve los tramos [{s,e,off}] (tiempo de la referencia),
 * los saltos detectados [{at,delta}] y el nº de ventanas útiles.
 * delta < 0: la pista tiene audio de más (se recorta) · delta > 0: la referencia metió silencio (se inserta).
 */
function studio_segment_sync(array $envRef, array $envTrack, float $o0, float $trackDur): array {
  $winSec = 24.0; $W = 5.0;
  $wins = studio_window_offsets($envRef, $envTrack, $o0, 12.0, $winSec, 6.0);
  $groups = studio_group_offsets($wins, $winSec);
  if (!$groups) {
    return ['segments' => [['s' => $o0, 'e' => $o0 + $trackDur, 'off' => $o0]], 'breaks' => [], 'windows' => count($wins)];
  }
  foreach ($groups as &$g) $g['off'] = studio_refine_range($envRef, $envTrack, $g['off'], $g['t0'], $g['t1']);
  unset($g);
  // Localizar cada salto con precisión: el punto donde deja de encajar el desfase anterior y empieza el nuevo
  $breaks = [];
  for ($k = 0; $k + 1 < count($groups); $k++) {
    $old = $groups[$k]['off']; $new = $groups[$k + 1]['off']; $delta = $new - $old;
    $lo = $groups[$k]['t1'] - $winSec / 2; $hi = $groups[$k + 1]['t0'] + $winSec / 2;
    $best = -INF; $bb = ($lo + $hi) / 2;
    for ($b = $lo; $b <= $hi; $b += 0.05) {
      $ns = $b + max(0.0, $delta);
      $sc = studio_ncc100($envRef, $envTrack, $b - $W, $b, $old) + studio_ncc100($envRef, $envTrack, $ns, $ns + $W, $new);
      if ($sc > $best + 1e-9) { $best = $sc; $bb = $b; }
    }
    $breaks[] = ['at' => round($bb, 2), 'delta' => round($delta, 3)];
  }
  // Tramos sobre la línea de tiempo de la referencia
  $segs = [];
  $last = count($groups) - 1;
  foreach ($groups as $k => $g) {
    $s = $k === 0 ? $g['off'] : $breaks[$k - 1]['at'] + max(0.0, $breaks[$k - 1]['delta']);
    $e = $k === $last ? $g['off'] + $trackDur : $breaks[$k]['at'];
    if ($e - $s > 0.05) $segs[] = ['s' => round($s, 3), 'e' => round($e, 3), 'off' => $g['off']];
  }
  return ['segments' => $segs, 'breaks' => $breaks, 'windows' => count($wins)];
}

/**
 * Reconstruye la pista siguiendo los tramos: corta el audio sobrante e inserta silencio donde
 * la referencia lo tiene, de modo que quede alineada con ella. El original se conserva como *.raw.wav.
 * Devuelve la duración de la copia alineada (empieza en segments[0].s de la referencia).
 */
function studio_bake_track(int $pid, string $tid, array $segs): float {
  $P = studio_track_paths($pid, $tid);
  if (!$P['has_raw']) { rename($P['wav'], $P['raw_wav_path']); rename($P['env'], $P['raw_env_path']); }
  $in = fopen($P['raw_wav_path'], 'rb');
  $info = studio_wav_info($P['raw_wav_path']);
  $tmp = $P['wav'] . '.tmp';
  $out = fopen($tmp, 'wb');
  fwrite($out, str_repeat("\0", 44));
  $sr = $info['sr']; $fade = (int)round(0.005 * $sr); $total = 0; $prevE = null;
  foreach ($segs as $i => $sg) {
    if ($prevE !== null && $sg['s'] > $prevE + 0.001) {                    // silencio insertado por la referencia
      $gap = (int)round(($sg['s'] - $prevE) * $sr);
      fwrite($out, str_repeat("\0\0", $gap)); $total += $gap;
    }
    $n = (int)round(($sg['e'] - $sg['s']) * $sr);
    $start = (int)round(($sg['s'] - $sg['off']) * $sr);
    studio_wav_copy($in, $info, $out, $start, $n, $i > 0 ? $fade : 0, $i < count($segs) - 1 ? $fade : 0);
    $total += $n; $prevE = $sg['e'];
  }
  fseek($out, 0); fwrite($out, studio_wav_header($total * 2, $sr));
  fclose($in); fclose($out);
  rename($tmp, $P['wav']);
  studio_env_build($P['wav'], $P['env']);
  return $total / $sr;
}

/** Devuelve la pista a su audio original (deshace un recorte previo). */
function studio_unbake_track(int $pid, string $tid): void {
  $P = studio_track_paths($pid, $tid);
  if (!$P['has_raw']) return;
  rename($P['raw_wav_path'], $P['wav']);
  rename($P['raw_env_path'], $P['env']);
}
