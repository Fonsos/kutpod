<?php
// ============================================================================
// KutPod · Estudio · palabras, muletillas y plan de cortes
// ============================================================================
if (!defined('KUTPOD_VERSION')) { exit; }

// ── Palabras y muletillas ───────────────────────────────────────────────────

function studio_norm(string $w): string {
  $w = mb_strtolower($w, 'UTF-8');
  $w = strtr($w, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
  return preg_replace('/[^\p{L}\p{N}ñç]+/u', '', $w);
}

/** Sonidos de relleno: eh, eeeh, ehm, mm, mmm, hmm, um, uhm… (no incluye palabras reales) */
function studio_is_filler_sound(string $w): bool {
  $n = studio_norm($w);
  return $n !== '' && preg_match('/^(?:e+h+m*|e{2,}h*m*|em+|ehm+|h?m{2,}h*|hm+|um+|uh+m*)$/u', $n) === 1;
}

/** Frases/palabras "tic" definidas en los ajustes (o sea, en plan…) → lista de listas de tokens */
function studio_tic_phrases(): array {
  static $cache = null;
  if ($cache !== null) return $cache;
  $cache = [];
  foreach (preg_split('/[,\n]+/u', (string)studio_opt('filler_words')) as $ph) {
    $toks = array_values(array_filter(array_map('studio_norm', preg_split('/\s+/u', trim($ph)))));
    if ($toks) $cache[] = $toks;
  }
  usort($cache, fn($a, $b) => count($b) <=> count($a));
  return $cache;
}

function studio_duration(array $proj): float {
  $d = 0.0;
  foreach (studio_voice_tracks($proj) as $t) $d = max($d, ($t['offset'] ?? 0) + ($t['duration'] ?? 0));
  return $d;
}

/**
 * Palabras de todas las pistas de voz en la línea de tiempo común, ordenadas.
 * kind: 'f' sonido de relleno · 't' muletilla-palabra · '' normal
 */
function studio_words(array $proj): array {
  $all = [];
  $voices = studio_voice_tracks($proj);
  $tics = studio_tic_phrases();
  foreach ($voices as $si => $t) {
    $tr = studio_transcript_load((int)$proj['id'], $t['id']);
    if (!$tr) continue;
    $off = (float)($t['offset'] ?? 0);
    $list = [];
    foreach ($tr['words'] as $i => $w) {
      $list[] = [
        'k' => $t['id'] . ':' . $i, 'tid' => $t['id'], 'spk' => $si, 'w' => $w['w'],
        's' => round($w['s'] + $off, 3), 'e' => round($w['e'] + $off, 3),
        'kind' => studio_is_filler_sound($w['w']) ? 'f' : '',
      ];
    }
    // Muletillas-palabra (admite frases de varias palabras)
    $norm = array_map(fn($x) => studio_norm($x['w']), $list);
    $n = count($list);
    for ($i = 0; $i < $n; $i++) {
      if ($list[$i]['kind'] !== '' || $norm[$i] === '') continue;
      foreach ($tics as $ph) {
        $len = count($ph);
        if ($i + $len > $n) continue;
        $ok = true;
        for ($j = 0; $j < $len; $j++) if ($norm[$i + $j] !== $ph[$j]) { $ok = false; break; }
        if ($ok) { for ($j = 0; $j < $len; $j++) $list[$i + $j]['kind'] = 't'; $i += $len - 1; break; }
      }
    }
    foreach ($list as $x) $all[] = $x;
  }
  usort($all, fn($a, $b) => $a['s'] <=> $b['s']);
  return $all;
}

// ── Plan de cortes ──────────────────────────────────────────────────────────

/** Unión de intervalos solapados/contiguos. */
function studio_merge_ranges(array $r, float $gap = 0.0): array {
  usort($r, fn($a, $b) => $a[0] <=> $b[0]);
  $out = [];
  foreach ($r as $x) {
    if ($x[1] <= $x[0]) continue;
    if ($out && $x[0] <= $out[count($out) - 1][1] + $gap) $out[count($out) - 1][1] = max($out[count($out) - 1][1], $x[1]);
    else $out[] = [$x[0], $x[1]];
  }
  return $out;
}

/** Resta de intervalos: $a menos $b. */
function studio_subtract_ranges(array $a, array $b): array {
  $out = [];
  foreach ($a as [$s, $e]) {
    $cur = $s;
    foreach ($b as [$bs, $be]) {
      if ($be <= $cur || $bs >= $e) continue;
      if ($bs > $cur) $out[] = [$cur, $bs];
      $cur = max($cur, $be);
    }
    if ($cur < $e) $out[] = [$cur, $e];
  }
  return $out;
}

function studio_overlaps(array $sorted, float $a, float $b): bool {
  // $sorted: [[s,e],…] por s. Búsqueda binaria del primer elemento con e > a.
  $lo = 0; $hi = count($sorted);
  while ($lo < $hi) { $m = ($lo + $hi) >> 1; if ($sorted[$m][1] > $a) $hi = $m; else $lo = $m + 1; }
  // las palabras pueden solaparse entre sí, retrocede un poco por seguridad
  for ($i = max(0, $lo - 3); $i < count($sorted) && $sorted[$i][0] < $b; $i++) {
    if ($sorted[$i][1] > $a && $sorted[$i][0] < $b) return true;
  }
  return false;
}

/** Punto de menor energía de la pista cerca de $t (tiempo común), dentro de [$t-$back, $t+$fwd]. */
function studio_snap_quiet(array $env, float $off, float $t, float $back, float $fwd): float {
  $i0 = (int)round(($t - $back - $off) * 100); $i1 = (int)round(($t + $fwd - $off) * 100);
  $n = count($env);
  if ($n === 0 || $i1 < 0 || $i0 >= $n) return $t;
  $i0 = max(0, $i0); $i1 = min($n - 1, $i1);
  $best = INF; $bi = $i0;
  for ($i = $i0; $i <= $i1; $i++) {
    $v = $env[$i] + 0.2 * abs($i - (int)round(($t - $off) * 100)) * 0.01;   // ligera preferencia por no alejarse
    if ($v < $best) { $best = $v; $bi = $i; }
  }
  return $bi / 100.0 + $off + 0.005;
}

/**
 * Plan de edición.
 * - cut:  tramos que se eliminan de TODAS las pistas
 * - mute: por pista, tramos que se silencian (alguien habla encima) sin acortar el episodio
 * - keep: tramos que se conservan (complemento de cut)
 */
function studio_plan(array $proj, ?array $words = null): array {
  $words = $words ?? studio_words($proj);
  $pid = (int)$proj['id'];
  $S = $proj['settings'];
  $pad = (float)studio_opt('pad_ms') / 1000;
  $del = array_flip($proj['cuts']['deleted'] ?? []);
  $D = studio_duration($proj);
  $voices = studio_voice_tracks($proj);

  $env = []; $off = [];
  foreach ($voices as $t) { $env[$t['id']] = studio_env_load($pid, $t['id']); $off[$t['id']] = (float)($t['offset'] ?? 0); }

  // Palabras por pista y palabras conservadas por pista
  $byTrack = []; $keptBy = [];
  foreach ($words as $w) {
    $byTrack[$w['tid']][] = $w;
    if (!isset($del[$w['k']])) $keptBy[$w['tid']][] = [$w['s'], $w['e']];
  }
  foreach ($keptBy as &$l) usort($l, fn($a, $b) => $a[0] <=> $b[0]);
  unset($l);

  $cut = []; $mute = [];
  $nDeleted = 0;
  foreach ($byTrack as $tid => $list) {
    $n = count($list);
    for ($i = 0; $i < $n; $i++) {
      if (!isset($del[$list[$i]['k']])) continue;
      $j = $i;
      while ($j + 1 < $n && isset($del[$list[$j + 1]['k']])) $j++;
      $nDeleted += $j - $i + 1;
      // vecinos conservados de la misma pista (límites duros del corte)
      $prevE = $i > 0 ? $list[$i - 1]['e'] : -INF;
      $nextS = $j + 1 < $n ? $list[$j + 1]['s'] : INF;
      $rs = $list[$i]['s'] - $pad; $re = $list[$j]['e'] + $pad;
      $rs = studio_snap_quiet($env[$tid], $off[$tid], $rs, 0.06, 0.03);
      $re = studio_snap_quiet($env[$tid], $off[$tid], $re, 0.03, 0.06);
      $rs = max($rs, $prevE + 0.005); $re = min($re, $nextS - 0.005);
      if ($re - $rs < 0.02) { $i = $j; continue; }
      // ¿habla alguien más a la vez? → solo silenciar esta pista
      $over = false;
      foreach ($keptBy as $otid => $kl) {
        if ($otid !== $tid && studio_overlaps($kl, $rs - 0.03, $re + 0.03)) { $over = true; break; }
      }
      if ($over) $mute[$tid][] = [$rs, $re]; else $cut[] = [$rs, $re];
      $i = $j;
    }
  }

  // Pausas largas y bordes: solo se recorta donde TODAS las pistas están en silencio real
  $thr = [];
  foreach ($env as $tid => $e) {
    if (!$e) { $thr[$tid] = -45.0; continue; }
    $c = $e; sort($c);
    $thr[$tid] = max(-60.0, min(-32.0, $c[(int)(count($c) * 0.10)] + 8));
  }
  $quiet = function (float $a, float $b) use ($env, $off, $thr): array {
    $runs = []; $start = null;
    for ($t = $a; $t <= $b; $t += 0.01) {
      $q = true;
      foreach ($env as $tid => $e) {
        $i = (int)round(($t - $off[$tid]) * 100);
        if ($i >= 0 && $i < count($e) && $e[$i] > $thr[$tid]) { $q = false; break; }
      }
      if ($q && $start === null) $start = $t;
      if (!$q && $start !== null) { if ($t - $start >= 0.15) $runs[] = [$start + 0.03, $t - 0.03]; $start = null; }
    }
    if ($start !== null && $b - $start >= 0.15) $runs[] = [$start + 0.03, $b];
    return $runs;
  };

  $act = [];
  foreach ($keptBy as $kl) foreach ($kl as $x) $act[] = $x;
  $act = studio_merge_ranges($act);
  $maxPause = (float)$S['max_pause'];
  $cands = [];
  if ($act) {
    if ($maxPause > 0) {
      for ($i = 0; $i + 1 < count($act); $i++) {
        $g0 = $act[$i][1]; $g1 = $act[$i + 1][0];
        if ($g1 - $g0 > $maxPause + 0.1) $cands[] = [$g0 + $maxPause / 2, $g1 - $maxPause / 2];
      }
    }
    if (!empty($S['trim_edges'])) {
      if ($act[0][0] > 0.35) $cands[] = [0.0, $act[0][0] - 0.25];
      $last = $act[count($act) - 1][1];
      if ($D - $last > 0.9) $cands[] = [$last + 0.6, $D];
    }
  }
  foreach ($cands as [$a, $b]) foreach ($quiet($a, $b) as $r) $cut[] = $r;

  $cut = studio_merge_ranges($cut, 0.005);
  // Lo silenciado dentro de algo ya recortado carece de sentido
  foreach ($mute as $tid => $rs) $mute[$tid] = studio_subtract_ranges(studio_merge_ranges($rs), $cut);
  $keep = studio_subtract_ranges([[0.0, $D]], $cut);
  $out = 0.0; foreach ($keep as [$a, $b]) $out += $b - $a;

  return [
    'cut' => $cut, 'mute' => $mute, 'keep' => $keep,
    'duration_src' => $D, 'duration_out' => $out,
    'stats' => ['deleted_words' => $nDeleted, 'saved' => $D - $out, 'cuts' => count($cut), 'mutes' => array_sum(array_map('count', $mute))],
  ];
}

/** Tiempo de la línea común → tiempo del episodio editado (solo voz, sin música). */
function studio_map_time(array $keep, float $t): float {
  $acc = 0.0;
  foreach ($keep as [$a, $b]) {
    if ($t < $a) return $acc;
    if ($t <= $b) return $acc + ($t - $a);
    $acc += $b - $a;
  }
  return $acc;
}

/** Palabras conservadas con sus tiempos en el episodio final (para subtítulos/shorts). */
function studio_final_words(array $plan, array $words, array $deleted, float $voiceOffset = 0.0): array {
  $del = array_flip($deleted);
  $out = [];
  foreach ($words as $w) {
    if (isset($del[$w['k']])) continue;
    $mid = ($w['s'] + $w['e']) / 2;
    $inKeep = false;
    foreach ($plan['keep'] as [$a, $b]) { if ($mid >= $a && $mid <= $b) { $inKeep = true; break; } if ($a > $mid) break; }
    if (!$inKeep) continue;
    $s = studio_map_time($plan['keep'], $w['s']) + $voiceOffset;
    $e = max($s + 0.05, studio_map_time($plan['keep'], $w['e']) + $voiceOffset);
    $out[] = ['w' => $w['w'], 's' => round($s, 3), 'e' => round($e, 3), 'spk' => $w['spk']];
  }
  return $out;
}

/** Subtítulos WebVTT a partir de palabras finales. */
function studio_vtt(array $fw, array $speakers): string {
  $fmt = function (float $t): string {
    $ms = (int)round($t * 1000);
    return sprintf('%02d:%02d:%02d.%03d', intdiv($ms, 3600000), intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), $ms % 1000);
  };
  $v = "WEBVTT\n\n";
  $cue = []; $n = 0;
  $flush = function () use (&$cue, &$v, &$n, $fmt, $speakers) {
    if (!$cue) return;
    $text = trim(implode(' ', array_column($cue, 'w')));
    $name = $speakers[$cue[0]['spk']] ?? '';
    $v .= (++$n) . "\n" . $fmt($cue[0]['s']) . ' --> ' . $fmt(end($cue)['e']) . "\n"
        . ($name !== '' ? '<v ' . str_replace(['<', '>', '&'], '', $name) . '>' : '') . $text . "\n\n";
    $cue = [];
  };
  foreach ($fw as $w) {
    if ($cue) {
      $prev = end($cue);
      $len = mb_strlen(implode(' ', array_column($cue, 'w')));
      if ($w['spk'] !== $prev['spk'] || $w['s'] - $prev['e'] > 0.8 || $len > 90 || $w['e'] - $cue[0]['s'] > 7
          || preg_match('/[.!?…]$/u', $prev['w'])) $flush();
    }
    $cue[] = $w;
  }
  $flush();
  return $v;
}
