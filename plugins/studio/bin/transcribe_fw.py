#!/usr/bin/env python3
"""Transcripción local con faster-whisper → JSON con timestamps por palabra.

Uso: transcribe_fw.py entrada.wav salida.json [--model large-v3] [--language es]
                                              [--device auto] [--prompt "..."]
Instalación: pip install faster-whisper
"""
import argparse
import json
import sys


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("input")
    ap.add_argument("output")
    ap.add_argument("--model", default="large-v3")
    ap.add_argument("--language", default="es")
    ap.add_argument("--device", default="auto")
    ap.add_argument("--prompt", default="")
    a = ap.parse_args()

    try:
        from faster_whisper import WhisperModel
    except ImportError:
        print("Falta faster-whisper: pip install faster-whisper", file=sys.stderr)
        sys.exit(2)

    compute = "float16" if a.device == "cuda" else "int8"
    model = WhisperModel(a.model, device=a.device, compute_type=compute)
    segments, info = model.transcribe(
        a.input,
        language=a.language or None,
        word_timestamps=True,
        initial_prompt=a.prompt or None,
        condition_on_previous_text=False,   # evita que "limpie" las muletillas por contexto
        vad_filter=False,                   # el VAD descarta justo los "eeeh"
        temperature=0.0,
    )
    total = max(info.duration, 1.0)
    words = []
    for seg in segments:
        for w in seg.words or []:
            words.append({"w": w.word.strip(), "s": round(w.start, 3), "e": round(w.end, 3)})
        print("PROGRESS %.3f" % min(1.0, seg.end / total), flush=True)
    with open(a.output, "w", encoding="utf-8") as f:
        json.dump({"words": words, "language": info.language}, f, ensure_ascii=False)


if __name__ == "__main__":
    main()
