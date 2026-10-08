<?php
// ============================================================================
// KutPod · Estudio · Shorts · lógica (lista, sugerencias, subtítulos ASS y render)
// ============================================================================
if (!defined('KUTPOD_VERSION')) { exit; }

function studio_short_list(int $pid): array {
  $f = studio_pdir($pid, 'render') . '/shorts.json';
  return is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
}
function studio_short_save(int $pid, array $list): void {
  file_put_contents(studio_pdir($pid, 'render') . '/shorts.json', json_encode(array_values($list), JSON_UNESCAPED_UNICODE));
}

// ── Sugerencias ─────────────────────────────────────────────────────────────

/** Frases como [inicio, fin] de índices de palabra (por puntuación o por pausa > 0,8 s). */
function studio_short_sentences(array $w): array {
  $out = []; $start = 0; $n = count($w);
  for ($i = 0; $i < $n; $i++) {
    $end = $i === $n - 1 || preg_match('/[.!?…]["»”)]*$/u', $w[$i]['w']) || ($w[$i + 1]['s'] - $w[$i]['e']) > 0.8;
    if ($end) { $out[] = [$start, $i]; $start = $i + 1; }
  }
  return $out;
}

/**
 * Propone hasta 3 fragmentos de ~45-60 s que empiezan y acaban en frase, con mucha voz y
 * cambios de interlocutor, fuera de la música de entradilla y de salida.
 */
function studio_short_suggest(array $w, float $from, float $to, float $target = 45.0, float $max = 62.0): array {
  $sent = studio_short_sentences($w);
  $cands = [];
  foreach ($sent as $a => [$ai, ]) {
    $s = $w[$ai]['s'];
    if ($s < $from - 0.1) continue;
    $b = null;
    foreach ($sent as $k => [, $bj]) {
      if ($k < $a) continue;
      $dur = $w[$bj]['e'] - $s;
      if ($dur > $max) break;
      $b = $k;
      if ($dur >= $target) break;
    }
    if ($b === null) continue;
    $bj = $sent[$b][1]; $e = $w[$bj]['e']; $dur = $e - $s;
    if ($e > $to + 0.1 || $dur < 15) continue;
    $words = $bj - $ai + 1; $changes = 0; $text = [];
    for ($i = $ai; $i <= $bj; $i++) { if ($i > $ai && $w[$i]['spk'] !== $w[$i - 1]['spk']) $changes++; if ($i < $ai + 28) $text[] = $w[$i]['w']; }
    $speech = 0.0; for ($i = $ai; $i <= $bj; $i++) $speech += $w[$i]['e'] - $w[$i]['s'];
    $score = ($words / $dur) * (1 + 0.15 * min($changes, 5)) * (0.4 + 0.6 * min(1.0, $speech / $dur)) * (1 - min(0.3, abs($dur - 55) / 100));
    $cands[] = ['s' => round($s, 2), 'e' => round($e, 2), 'duration' => round($dur, 1), 'score' => round($score, 3), 'changes' => $changes, 'excerpt' => implode(' ', $text) . ($bj - $ai >= 28 ? '…' : '')];
  }
  usort($cands, fn($x, $y) => $y['score'] <=> $x['score']);
  $pick = [];
  foreach ($cands as $c) {
    foreach ($pick as $p) if ($c['s'] < $p['e'] && $c['e'] > $p['s']) continue 2;
    $pick[] = $c;
    if (count($pick) === 3) break;
  }
  return $pick;
}

// ── Subtítulos ASS ──────────────────────────────────────────────────────────

function studio_short_ass_time(float $t): string {
  $cs = (int)round(max(0, $t) * 100);
  return sprintf('%d:%02d:%02d.%02d', intdiv($cs, 360000), intdiv($cs % 360000, 6000), intdiv($cs % 6000, 100), $cs % 100);
}
function studio_short_ass_text(string $s): string {
  return str_replace(['\\', '{', '}', "\n", "\r"], ['', '(', ')', ' ', ''], $s);
}
/** #RRGGBB → &H00BBGGRR (ASS) */
function studio_short_ass_color(string $hex, string $fallback = 'FFD43B'): string {
  $hex = ltrim($hex, '#');
  if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) $hex = $fallback;
  return '&H00' . strtoupper(substr($hex, 4, 2) . substr($hex, 2, 2) . substr($hex, 0, 2));
}
function studio_short_wrap(string $t, int $width): string {
  return implode('\\N', array_map('studio_short_ass_text', explode("\n", wordwrap($t, $width, "\n", true))));
}

/**
 * Subtítulos con resaltado palabra a palabra + título y nombre del podcast.
 * $words: palabras con tiempos relativos al inicio del fragmento.
 */
function studio_short_ass(array $words, float $dur, string $title, string $show, string $accent): string {
  $acc = studio_short_ass_color($accent);
  $ass = "[Script Info]\nScriptType: v4.00+\nPlayResX: 1080\nPlayResY: 1920\nWrapStyle: 0\nScaledBorderAndShadow: yes\n\n"
       . "[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
       . "Style: Title,Inter,66,&H00FFFFFF,&H00FFFFFF,&H00000000,&H80000000,1,0,0,0,100,100,0,0,1,4,1,8,70,70,170,1\n"
       . "Style: Show,Inter,38,$acc,$acc,&H00000000,&H80000000,1,0,0,0,100,100,3,0,1,3,0,8,70,70,95,1\n"
       . "Style: Cap,Inter,74,$acc,&H00FFFFFF,&H00000000,&H80000000,1,0,0,0,100,100,0,0,1,6,0,2,70,70,190,1\n\n"
       . "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
  $end = studio_short_ass_time($dur);
  if ($show !== '') $ass .= "Dialogue: 0,0:00:00.00,$end,Show,,0,0,0,," . studio_short_ass_text(mb_strtoupper($show, 'UTF-8')) . "\n";
  if ($title !== '') $ass .= "Dialogue: 0,0:00:00.00,$end,Title,,0,0,0,," . studio_short_wrap($title, 24) . "\n";
  // Grupos de subtítulo: ≤ 4 palabras y ≤ 26 caracteres; se cortan en puntuación o pausa
  $groups = []; $cur = []; $len = 0;
  foreach ($words as $i => $w) {
    $cur[] = $w; $len += mb_strlen($w['w']) + 1;
    $next = $words[$i + 1] ?? null;
    if (!$next || count($cur) >= 4 || $len > 26 || preg_match('/[.!?,;:…]$/u', $w['w']) || ($next['s'] - $w['e']) > 0.45) { $groups[] = $cur; $cur = []; $len = 0; }
  }
  foreach ($groups as $gi => $g) {
    $start = max(0.0, $g[0]['s'] - 0.04);
    $stop = end($g)['e'] + 0.12;
    if (isset($groups[$gi + 1])) $stop = min($stop, $groups[$gi + 1][0]['s'] - 0.02);
    $stop = max($stop, $start + 0.2);
    $txt = '';
    foreach ($g as $k => $w) {
      $nextS = $g[$k + 1]['s'] ?? end($g)['e'];
      $cs = max(1, (int)round(($nextS - $w['s']) * 100));
      $txt .= '{\\k' . $cs . '}' . studio_short_ass_text($w['w']) . ' ';
    }
    $ass .= 'Dialogue: 1,' . studio_short_ass_time($start) . ',' . studio_short_ass_time($stop) . ",Cap,,0,0,0,," . rtrim($txt) . "\n";
  }
  return $ass;
}

// ── Render ──────────────────────────────────────────────────────────────────

function studio_short_job(int $pid, array $params, callable $progress): array {
  $proj = studio_project($pid);
  $rdir = studio_pdir($pid, 'render');
  $mp3 = "$rdir/episode.mp3"; $wf = "$rdir/final_words.json";
  if (!is_file($mp3) || !is_file($wf)) throw new RuntimeException('Primero exporta el episodio');
  $words = json_decode(file_get_contents($wf), true) ?: [];
  $total = (float)($proj['result']['duration'] ?? 0);
  $s = max(0.0, (float)($params['s'] ?? 0)); $e = (float)($params['e'] ?? 0);
  if ($total > 0) $e = min($e, $total);
  $dur = $e - $s;
  if ($dur < 3) throw new RuntimeException('El fragmento es demasiado corto (mínimo 3 s)');
  if ($dur > 180) throw new RuntimeException('El fragmento es demasiado largo (máximo 3 min)');
  $title = trim(mb_substr((string)($params['title'] ?? $proj['title']), 0, 90));
  $podcast = kp_one("SELECT title, cover, color FROM podcasts WHERE id = ?", [$proj['podcast_id']]) ?: [];
  $show = trim(mb_substr((string)($params['show'] ?? ($podcast['title'] ?? '')), 0, 60));
  $accent = (string)($podcast['color'] ?? '');

  $list = studio_short_list($pid);
  $id = $list ? max(array_column($list, 'id')) + 1 : 1;
  $sel = [];
  foreach ($words as $w) if ($w['s'] >= $s - 0.02 && $w['e'] <= $e + 0.1) $sel[] = ['w' => $w['w'], 's' => round($w['s'] - $s, 3), 'e' => round($w['e'] - $s, 3)];
  $assName = "short_$id.ass"; $out = "short_$id.mp4";
  file_put_contents("$rdir/$assName", studio_short_ass($sel, $dur, $title, $show, $accent));

  // Portada del podcast (o un fondo liso con su color)
  $cover = '';
  if (!empty($podcast['cover'])) {
    $c = realpath(__DIR__ . '/../../../') . '/' . ltrim((string)$podcast['cover'], '/');
    if (is_file($c)) $cover = $c;
  }
  $bgHex = preg_match('/^#?[0-9a-fA-F]{6}$/', $accent) ? ltrim($accent, '#') : '3b3b52';
  // Imagen base (fondo desenfocado + portada): se calcula una sola vez y se repite como fotograma fijo
  $base = "$rdir/short_{$id}_base.png";
  $progress(0.01, 'Preparando fondo…');
  if ($cover !== '') {
    [$c0, $e0] = studio_ffmpeg(['-i', $cover, '-filter_complex',
      '[0:v]split=2[bgsrc][fgsrc];[bgsrc]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,gblur=sigma=45,eq=brightness=-0.32[bg];'
      . '[fgsrc]scale=860:860:force_original_aspect_ratio=increase,crop=860:860[cv];[bg][cv]overlay=(W-w)/2:400,format=rgb24',
      '-frames:v', '1', $base]);
  } else {
    [$c0, $e0] = studio_ffmpeg(['-f', 'lavfi', '-i', "color=c=0x$bgHex:s=1080x1920", '-frames:v', '1', $base]);
  }
  if ($c0 !== 0 || !is_file($base)) { @unlink("$rdir/$assName"); throw new RuntimeException('No se pudo preparar el fondo: ' . trim(substr($e0, -300))); }
  $fo = max(0.1, $dur - 0.4);
  // Forma de onda: audio reforzado para que se vea, sobre fondo transparente (colorkey del negro)
  $filter = "[1:a]asplit=2[a1][a2];[a2]volume=3,showwaves=s=860x170:mode=cline:rate=30:scale=sqrt:draw=full:colors=0xFFFFFF,format=rgba,colorkey=0x000000:0.3:0.15[wv];"
    . "[0:v][wv]overlay=(W-w)/2:1285[v2];[v2]ass=$assName,format=yuv420p[vout];"
    . "[a1]afade=t=in:d=0.25,afade=t=out:st=" . sprintf('%.2f', $fo) . ":d=0.4[aout]";
  $progress(0.04, 'Generando vídeo…');
  [$code, $err] = studio_ffmpeg(['-loop', '1', '-framerate', '30', '-i', $base, '-ss', sprintf('%.3f', $s), '-t', sprintf('%.3f', $dur), '-i', $mp3,
    '-filter_complex', $filter, '-map', '[vout]', '-map', '[aout]', '-t', sprintf('%.3f', $dur),
    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21', '-r', '30', '-pix_fmt', 'yuv420p',
    '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart', "$out.tmp.mp4"],
    $dur, fn($f) => $progress(0.04 + 0.94 * $f, 'Generando vídeo…'), $rdir);
  @unlink($base);
  @unlink("$rdir/$assName");
  if ($code !== 0) { @unlink("$rdir/$out.tmp.mp4"); throw new RuntimeException('ffmpeg falló al crear el vídeo: ' . trim(substr($err, -500))); }
  rename("$rdir/$out.tmp.mp4", "$rdir/$out");
  $list[] = ['id' => $id, 'file' => $out, 'title' => $title, 's' => round($s, 2), 'e' => round($e, 2), 'duration' => round($dur, 1),
             'bytes' => filesize("$rdir/$out"), 'created_at' => gmdate('Y-m-d H:i:s')];
  studio_short_save($pid, $list);
  return ['short' => $id, 'file' => $out];
}
