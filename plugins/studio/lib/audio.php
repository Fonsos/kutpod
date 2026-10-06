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

/** Envolvente como array de dB (índice = trama de 10 ms de la pista). */
function studio_env_load(int $pid, string $tid): array {
  $f = studio_pdir($pid, 'tracks') . "/$tid.env";
  if (!is_file($f)) return [];
  return array_map(fn($b) => $b / 2.8 - 90, array_values(unpack('C*', file_get_contents($f))));
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
