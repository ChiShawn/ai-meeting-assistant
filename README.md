# AI 會議記錄助手

English title: AI Meeting Minutes Assistant

AI Meeting Minutes Assistant is a web application for turning long meeting audio into searchable transcripts, summaries, action items, and Word meeting reports. It combines a PHP/Apache frontend proxy with a FastAPI backend and an optional remote GPU service for Whisper ASR and LLM inference.

This repository is packaged as a portfolio-safe version of a production-style internal tool. Sensitive deployment files, logs, sample recordings, generated reports, and credentials are intentionally excluded.

## Highlights

- Async audio transcription workflow with upload, job polling, progress tracking, and automatic task cleanup.
- FastAPI backend for ASR orchestration, LLM summarization, action-item extraction, meeting-minute item generation, and Word report generation.
- PHP proxy layer that forwards browser requests to the Python backend through a Unix socket, keeping the browser-facing endpoint simple for Apache hosting.
- Remote GPU backend option using faster-whisper and a Hugging Face causal language model.
- Transcript normalization for multiple timestamp formats and segment shapes.
- Domain correction support through user-managed replacement dictionaries and optional medical-term annotation.
- DOCX template filling with placeholder replacement, heading-aware insertion, fallback classification, and content cleanup.

## Tech Stack

- Backend: Python, FastAPI, Uvicorn, httpx, Pydantic
- AI/ML: faster-whisper, Transformers, PyTorch
- Frontend: HTML, CSS, vanilla JavaScript
- Proxy/runtime: PHP 7.4 + Apache, Unix socket forwarding
- Documents: python-docx

## Architecture

```text
Browser
  -> frontend/proxy.php
  -> frontend/meeting.sock
  -> app.py FastAPI backend
  -> REMOTE_ASR_URL / VLLM_API_URL
  -> optional remote_gpu_server.py
```

Main endpoints:

- `POST /transcribe_async`: upload audio and create an async transcription job.
- `GET /task/{task_id}`: poll transcription status and retrieve the result.
- `POST /summarize`: generate a structured meeting summary.
- `POST /todo`: extract action items.
- `POST /minutes_items`: create meeting-minute items for Word templates.
- `POST /generate_report_docx`: fill a `.docx` meeting report template.
- `GET /health`: health check.

## Project Layout

```text
.
├── app.py                    # Main FastAPI application
├── remote_gpu_server.py      # Optional GPU ASR/LLM backend
├── frontend/
│   ├── index.html            # Browser UI
│   ├── script.js             # Alternate UI logic / workflow helpers
│   ├── style.css             # UI styling
│   └── proxy.php             # Apache/PHP to FastAPI socket proxy
├── requirements.txt
├── run_meeting_app.sh        # Local production startup script
├── .env.example
└── services_lite.example.json
```

## Local Setup

Install system dependencies first:

```bash
sudo apt-get install -y ffmpeg php-cli
```

Create a virtual environment and install Python dependencies:

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

Copy the environment example and adjust backend URLs:

```bash
cp .env.example .env
```

Start the FastAPI backend for local development:

```bash
uvicorn app:app --host 127.0.0.1 --port 9005
```

For Apache/PHP deployment, run through the Unix socket path used by `frontend/proxy.php`:

```bash
./run_meeting_app.sh
```

## Optional GPU Backend

The remote GPU backend can serve:

- `/asr` for faster-whisper transcription.
- `/v1/chat/completions` for OpenAI-compatible chat completion requests.
- `/corrections` endpoints for correction dictionary management.

Example:

```bash
uvicorn remote_gpu_server:app --host 0.0.0.0 --port 18080
```

Then configure:

```bash
REMOTE_ASR_URL=http://127.0.0.1:18080/asr
VLLM_API_URL=http://127.0.0.1:18080/v1/chat/completions
```

## Security Notes

- Do not commit `.env`, logs, generated documents, recordings, SSH details, or real service configuration.
- Use `services_lite.example.json` as the public template. Keep the real `services_lite.json` private.
- The public repo should include only source code, examples, and documentation needed to understand and run the project.

## Resume Summary

Built an AI meeting-minutes system that transcribes long audio asynchronously, summarizes meeting content with LLMs, extracts action items, and generates Word reports from templates. Designed a PHP/Apache frontend proxy, FastAPI orchestration backend, remote GPU inference path, transcript normalization, correction dictionaries, and DOCX automation workflow.
