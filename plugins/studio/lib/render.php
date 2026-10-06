<?php
// ============================================================================
// KutPod · Estudio · mezcla y exportación del episodio
// ============================================================================
if (!defined('KUTPOD_VERSION')) { exit; }

/** Vista previa: mezcla mono ligera de las pistas de voz sincronizadas (para el editor). */
function studio_build_preview(int $pid, array $proj, ?callable $onProgress = null): void {
  $voices = studio_voice_tracks($proj);
  if (!$voices) throw new RuntimeException('No hay pistas de voz listas');
  $dir = studio_pdir($pid, 'tracks');
  $args = []; $f = []; $labels = [];
  foreach ($voices as $i => $t) {
    $args[] = '-i'; $args[] = "$dir/{$t['id']}.wav";
    $ms = (int)round(($t['offset'] ?? 0) * 1000);
    $f[] = "[$i:a]adelay={$ms}[a$i]";
    $labels[] = "[a$i]";
  }
  $f[] = count($voices) > 1
    ? implode('', $labels) . 'amix=inputs=' . count($voices) . ':normalize=0:dropout_transition=0,alimiter=limit=0.95[m]'
    : $labels[0] . 'anull[m]';
  $out = studio_pdir($pid) . '/preview.mp3';
  [$c, $err] = studio_ffmpeg(array_merge($args, ['-filter_complex', implode(';', $f), '-map', '[m]', '-ac', '1', '-ar', '32000',
    '-c:a', 'libmp3lame', '-b:a', '56k', $out . '.tmp.mp3']), studio_duration($proj), $onProgress);
  if ($c !== 0) throw new RuntimeException('No se pudo crear la vista previa: ' . trim(substr($err, -400)));
  rename($out . '.tmp.mp3', $out);
}

/** Genera el WAV editado de una pista aplicando cortes y silencios del plan. Devuelve nº de muestras. */
function studio_edit_track(int $pid, array $track, array $plan, ?callable $tick = null): int {
  $src = studio_pdir($pid, 'tracks') . '/' . $track['id'] . '.wav';
  $dst = studio_pdir($pid, 'render') . '/edit_' . $track['id'] . '.wav';
  $info = studio_wav_info($src);
  if (!$info) throw new RuntimeException('Copia de trabajo ilegible: ' . $track['name']);
  $in = fopen($src, 'rb'); $out = fopen($dst, 'wb');
  fwrite($out, str_repeat("\0", 44));
  $off = (float)($track['offset'] ?? 0);
  $sr = $info['sr'];
  $mutes = $plan['mute'][$track['id']] ?? [];
  $fade = (int)round(0.006 * $sr);
  $total = 0;
  foreach ($plan['keep'] as [$a, $b]) {
    $n = (int)round(($b - $a) * $sr);
    if ($n <= 0) continue;
    // piezas dentro del tramo: reproducir / silenciar
    $pieces = []; $cur = 0;
    foreach ($mutes as [$ms, $me]) {
      if ($me <= $a || $ms >= $b) continue;
      $x = max(0, (int)round((max($ms, $a) - $a) * $sr)); $y = min($n, (int)round((min($me, $b) - $a) * $sr));
      if ($x > $cur) $pieces[] = ['p', $cur, $x];
      if ($y > $x)   $pieces[] = ['m', $x, $y];
      $cur = max($cur, $y);
    }
    if ($cur < $n) $pieces[] = ['p', $cur, $n];
    foreach ($pieces as [$kind, $x, $y]) {
      $cnt = $y - $x;
      if ($kind === 'm') { fwrite($out, str_repeat("\0\0", $cnt)); continue; }
      $start = (int)round(($a - $off) * $sr) + $x;
      $f = min($fade, intdiv($cnt, 2));
      studio_wav_copy($in, $info, $out, $start, $cnt, $f, $f);
    }
    $total += $n;
    if ($tick) $tick($n);
  }
  fseek($out, 0); fwrite($out, studio_wav_header($total * 2, $sr));
  fclose($in); fclose($out);
  return $total;
}

function studio_music_file(int $pid, array $proj, string $kind): ?string {
  $m = $proj['music'][$kind] ?? null;
  if (!$m || empty($m['file'])) return null;
  $f = studio_pdir($pid, 'music') . '/' . basename($m['file']);
  return is_file($f) ? $f : null;
}

/** Exporta el episodio completo. Devuelve el resultado (ruta, duración, palabras finales…). */
function studio_render(int $pid, ?callable $progress = null): array {
  $progress = $progress ?? function ($f, $m = '') {};
  $proj = studio_project($pid);
  $voices = studio_voice_tracks($proj);
  if (!$voices) throw new RuntimeException('No hay pistas de voz listas');
  $words = studio_words($proj);
  $plan = studio_plan($proj, $words);
  $S = $proj['settings'];
  $sr = STUDIO_SR;
  $rdir = studio_pdir($pid, 'render');

  // 1) Edición de cada pista (cortes sample-accurate)
  $progress(0.02, 'Aplicando cortes…');
  $grand = max(1, count($voices) * array_sum(array_map(fn($k) => (int)round(($k[1] - $k[0]) * $sr), $plan['keep'])));
  $done = 0; $lens = [];
  foreach ($voices as $t) {
    $lens[$t['id']] = studio_edit_track($pid, $t, $plan, function ($n) use (&$done, $grand, $progress) {
      $done += $n; $progress(0.02 + 0.33 * min(1, $done / $grand), 'Aplicando cortes…');
    });
  }
  $voiceLen = max($lens) / $sr;

  // 2) Grafo de mezcla
  $ch = (int)studio_opt('channels') === 1 ? 1 : 2;
  $layout = $ch === 1 ? 'mono' : 'stereo';
  $args = []; $f = []; $vl = [];
  foreach ($voices as $i => $t) {
    $args[] = '-i'; $args[] = "$rdir/edit_{$t['id']}.wav";
    $chain = ['highpass=f=70'];
    if (!empty($S['denoise'])) $chain[] = 'afftdn=nr=12:nf=-50';
    if (!empty($S['compress'])) $chain[] = 'acompressor=threshold=0.089:ratio=3:attack=15:release=250:makeup=2';
    $g = (float)($t['gain_db'] ?? 0);
    if ($g != 0) $chain[] = sprintf('volume=%.1fdB', $g);
    $f[] = "[$i:a]" . implode(',', $chain) . "[v$i]";
    $vl[] = "[v$i]";
  }
  $f[] = count($voices) > 1
    ? implode('', $vl) . 'amix=inputs=' . count($voices) . ':normalize=0:dropout_transition=0[voice0]'
    : $vl[0] . 'anull[voice0]';
  $f[] = "[voice0]aformat=channel_layouts=$layout,aresample={$sr}[voice1]";

  $idx = count($voices);
  $mix = ['[voice1]']; $voiceDelay = 0.0;
  $gain = (float)$S['music_gain'];
  $intro = studio_music_file($pid, $proj, 'intro');
  $outro = studio_music_file($pid, $proj, 'outro');
  $introLen = 0.0; $outroLen = 0.0;
  if ($intro) {
    $pi = studio_probe($intro); $introLen = $pi['duration'] ?? 0;
    $ov = min((float)$S['intro_overlap'], $introLen, $voiceLen / 2);
    $args[] = '-i'; $args[] = $intro;
    $c = ["aresample=$sr", "aformat=channel_layouts=$layout", sprintf('volume=%.1fdB', $gain)];
    if ($ov > 0) $c[] = sprintf('afade=t=out:st=%.3f:d=%.3f', $introLen - $ov, $ov);
    $f[] = "[$idx:a]" . implode(',', $c) . "[intro]";
    $mix[] = '[intro]'; $voiceDelay = max(0.0, $introLen - $ov);
    $idx++;
  }
  if ($voiceDelay > 0) {
    $ms = (int)round($voiceDelay * 1000);
    $f[] = "[voice1]adelay=" . implode('|', array_fill(0, $ch, $ms)) . "[voice2]";
    $mix[0] = '[voice2]';
  }
  if ($outro) {
    $po = studio_probe($outro); $outroLen = $po['duration'] ?? 0;
    $ov = min((float)$S['outro_overlap'], $outroLen, $voiceLen / 2);
    $args[] = '-i'; $args[] = $outro;
    $c = ["aresample=$sr", "aformat=channel_layouts=$layout", sprintf('volume=%.1fdB', $gain)];
    if ($ov > 0) $c[] = sprintf('afade=t=in:st=0:d=%.3f', $ov);
    $ms = (int)round(($voiceDelay + $voiceLen - $ov) * 1000);
    $c[] = 'adelay=' . implode('|', array_fill(0, $ch, $ms));
    $f[] = "[$idx:a]" . implode(',', $c) . "[outro]";
    $mix[] = '[outro]';
  }
  $f[] = count($mix) > 1
    ? implode('', $mix) . 'amix=inputs=' . count($mix) . ':normalize=0:duration=longest:dropout_transition=0[pre]'
    : $mix[0] . 'anull[pre]';
  $totalLen = $voiceDelay + $voiceLen + ($outro ? max(0, $outroLen - min((float)$S['outro_overlap'], $outroLen, $voiceLen / 2)) : 0);
  $graph = implode(';', $f);

  // 3) Normalización de sonoridad en dos pasadas (EBU R128)
  $target = (float)$S['lufs'];
  $ln = sprintf('loudnorm=I=%.1f:TP=-1.5:LRA=11', $target);
  $progress(0.36, 'Midiendo sonoridad…');
  [$c1, , $err1] = studio_run(array_merge([studio_bin('ffmpeg'), '-hide_banner', '-nostdin', '-y', '-v', 'info'],
    $args, ['-filter_complex', "$graph;[pre]$ln:print_format=json[o]", '-map', '[o]', '-f', 'null', '-']));
  if ($c1 !== 0) throw new RuntimeException('Error al medir la mezcla: ' . trim(substr($err1, -500)));
  $lnFinal = $ln;
  if (preg_match('/\{[^{}]*"input_i"[^{}]*\}/s', $err1, $m) && ($j = json_decode($m[0], true))) {
    $lnFinal .= sprintf(':measured_I=%s:measured_TP=%s:measured_LRA=%s:measured_thresh=%s:offset=%s:linear=true',
      $j['input_i'], $j['input_tp'], $j['input_lra'], $j['input_thresh'], $j['target_offset']);
  }
  $progress(0.55, 'Codificando MP3…');
  $podcast = kp_one("SELECT title, author FROM podcasts WHERE id = ?", [$proj['podcast_id']]) ?: [];
  $mp3 = "$rdir/episode.mp3";
  [$c2, $err2] = studio_ffmpeg(array_merge($args, [
    '-filter_complex', "$graph;[pre]$lnFinal,aresample={$sr}[o]", '-map', '[o]',
    '-ac', (string)$ch, '-c:a', 'libmp3lame', '-b:a', ((int)studio_opt('mp3_kbps') ?: 128) . 'k',
    '-id3v2_version', '3', '-metadata', 'title=' . $proj['title'],
    '-metadata', 'artist=' . ($podcast['author'] ?? ''), '-metadata', 'album=' . ($podcast['title'] ?? ''),
    "$mp3.tmp.mp3",
  ]), $totalLen, fn($fr) => $progress(0.55 + 0.4 * $fr, 'Codificando MP3…'));
  if ($c2 !== 0) throw new RuntimeException('Error al exportar: ' . trim(substr($err2, -500)));
  rename("$mp3.tmp.mp3", $mp3);

  // 4) Limpieza de temporales y salida de transcripción final
  foreach ($voices as $t) @unlink("$rdir/edit_{$t['id']}.wav");
  $progress(0.97, 'Generando transcripción final…');
  $fw = studio_final_words($plan, $words, $proj['cuts']['deleted'] ?? [], $voiceDelay);
  file_put_contents("$rdir/final_words.json", json_encode($fw, JSON_UNESCAPED_UNICODE));
  $speakers = array_map(fn($t) => $t['name'], $voices);
  file_put_contents("$rdir/episode.vtt", studio_vtt($fw, $speakers));
  $pr = studio_probe($mp3);
  return [
    'file' => 'episode.mp3', 'duration' => round($pr['duration'] ?? $totalLen, 2), 'bytes' => filesize($mp3),
    'voice_offset' => $voiceDelay, 'voice_len' => round($voiceLen, 2), 'saved' => round($plan['stats']['saved'], 1),
    'rendered_at' => gmdate('Y-m-d H:i:s'), 'speakers' => $speakers,
  ];
}
