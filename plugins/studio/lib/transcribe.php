<?php
// ============================================================================
// KutPod · Estudio · transcripción palabra a palabra por pista
// ============================================================================
if (!defined('KUTPOD_VERSION')) { exit; }

function studio_transcript_path(int $pid, string $tid): string {
  return studio_pdir($pid, 'transcripts') . "/$tid.json";
}

function studio_transcript_load(int $pid, string $tid): ?array {
  $f = studio_transcript_path($pid, $tid);
  return is_file($f) ? json_decode(file_get_contents($f), true) : null;
}

/** Transcribe una pista. Devuelve la lista de palabras [{w,s,e}] en tiempo de la pista. */
function studio_transcribe_track(int $pid, array $track, ?callable $onProgress = null): array {
  $wav = studio_pdir($pid, 'tracks') . '/' . $track['id'] . '.wav';
  if (!is_file($wav)) throw new RuntimeException('Falta la copia de trabajo de la pista');
  $engine = studio_opt('engine');
  $words = $engine === 'faster-whisper'
    ? studio_transcribe_fw($pid, $track['id'], $wav, $onProgress)
    : studio_transcribe_api($pid, $track['id'], $wav, $onProgress);

  usort($words, fn($a, $b) => $a['s'] <=> $b['s']);
  file_put_contents(studio_transcript_path($pid, $track['id']),
    json_encode(['engine' => $engine, 'language' => studio_opt('language'), 'words' => $words], JSON_UNESCAPED_UNICODE));
  return $words;
}

// ── Motor local: faster-whisper ─────────────────────────────────────────────

function studio_transcribe_fw(int $pid, string $tid, string $wav, ?callable $onProgress): array {
  $tmp16 = studio_pdir($pid, 'tmp') . "/$tid-16k.wav";
  [$c, $err] = studio_ffmpeg(['-i', $wav, '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', $tmp16]);
  if ($c !== 0) throw new RuntimeException('No se pudo preparar el audio: ' . trim(substr($err, -300)));
  $out = studio_pdir($pid, 'tmp') . "/$tid-fw.json";
  @unlink($out);
  $cmd = [studio_opt('python'), __DIR__ . '/../bin/transcribe_fw.py', $tmp16, $out,
          '--model', studio_opt('fw_model'), '--language', studio_opt('language'),
          '--device', studio_opt('fw_device'), '--prompt', studio_opt('prompt')];
  [$code, , $err] = studio_run($cmd, function ($l) use ($onProgress) {
    if ($onProgress && str_starts_with($l, 'PROGRESS ')) $onProgress((float)substr($l, 9));
  }, null, false);
  @unlink($tmp16);
  if ($code !== 0 || !is_file($out)) {
    throw new RuntimeException('faster-whisper falló (' . $code . '): ' . trim(substr($err, -500)));
  }
  $j = json_decode(file_get_contents($out), true);
  @unlink($out);
  return $j['words'] ?? [];
}

// ── Motor remoto: API compatible con OpenAI (OpenAI, Groq, servidores locales) ──

function studio_transcribe_api(int $pid, string $tid, string $wav, ?callable $onProgress): array {
  if (!function_exists('curl_init')) throw new RuntimeException('La extensión cURL de PHP es necesaria para el motor API');
  $key = trim((string)studio_opt('api_key'));
  $url = rtrim((string)studio_opt('api_url'), '/') . '/audio/transcriptions';
  $info = studio_wav_info($wav);
  $total = $info['bytes'] / 2 / $info['sr'];
  $env = studio_env_load($pid, $tid);
  $chunk = 600.0;                                   // trozos de ~10 min (límite de 25 MB de la API)
  $words = [];
  $start = 0.0;
  while ($start < $total - 0.05) {
    $end = $start + $chunk;
    if ($end >= $total - 30) {
      $end = $total;
    } else {
      $end = studio_quietest($env, $end - 20, $end + 20);   // corta en un silencio, no en mitad de una palabra
    }
    $mp3 = studio_pdir($pid, 'tmp') . "/$tid-chunk.mp3";
    [$c, $err] = studio_ffmpeg(['-ss', sprintf('%.3f', $start), '-t', sprintf('%.3f', $end - $start), '-i', $wav,
      '-ac', '1', '-ar', '16000', '-c:a', 'libmp3lame', '-b:a', '48k', $mp3]);
    if ($c !== 0) throw new RuntimeException('Error al trocear el audio: ' . trim(substr($err, -300)));

    $res = studio_api_call($url, $key, $mp3);
    @unlink($mp3);
    foreach ($res['words'] ?? [] as $w) {
      $t = trim((string)($w['word'] ?? $w['w'] ?? ''));
      if ($t === '') continue;
      $words[] = ['w' => $t, 's' => round($start + (float)$w['start'], 3), 'e' => round($start + (float)$w['end'], 3)];
    }
    if (empty($res['words']) && !empty(trim((string)($res['text'] ?? '')))) {
      throw new RuntimeException('El servicio no devuelve timestamps por palabra. Usa un modelo/servidor que soporte timestamp_granularities=word.');
    }
    $start = $end;
    if ($onProgress) $onProgress(min(1.0, $start / $total));
  }
  return $words;
}

function studio_api_call(string $url, string $key, string $file): array {
  $last = '';
  for ($try = 1; $try <= 3; $try++) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 1200,
      CURLOPT_CONNECTTIMEOUT => 20,
      CURLOPT_HTTPHEADER => $key !== '' ? ['Authorization: Bearer ' . $key] : [],
      CURLOPT_POSTFIELDS => [
        'file' => new CURLFile($file, 'audio/mpeg', 'chunk.mp3'),
        'model' => studio_opt('api_model'),
        'language' => studio_opt('language'),
        'response_format' => 'verbose_json',
        'timestamp_granularities[]' => 'word',
        'temperature' => '0',
        'prompt' => studio_opt('prompt'),
      ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($code === 200 && $body) {
      $j = json_decode($body, true);
      if (is_array($j)) return $j;
    }
    $last = $cerr ?: ('HTTP ' . $code . ' ' . substr((string)$body, 0, 300));
    if ($code >= 400 && $code < 500 && $code !== 429) break;     // no tiene sentido reintentar
    sleep(2 * $try);
  }
  throw new RuntimeException('Error en la API de transcripción: ' . $last);
}

/** Tiempo (s) del punto más silencioso de la envolvente entre $a y $b (ventana de 300 ms). */
function studio_quietest(array $env, float $a, float $b): float {
  $i0 = max(0, (int)($a * 100)); $i1 = min(count($env) - 30, (int)($b * 100));
  $best = INF; $bi = (int)(($a + $b) * 50);
  for ($i = $i0; $i < $i1; $i += 5) {
    $s = 0.0; for ($k = 0; $k < 30; $k += 3) $s += $env[$i + $k];
    if ($s < $best) { $best = $s; $bi = $i + 15; }
  }
  return $bi / 100.0;
}
