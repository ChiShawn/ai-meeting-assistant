#!/bin/bash
# Startup script for Meeting App on Port 9005
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$APP_DIR"

export VLLM_API_URL="${VLLM_API_URL:-http://127.0.0.1:18080/v1/chat/completions}"
export VLLM_MODEL_NAME="${VLLM_MODEL_NAME:-Qwen/Qwen3-0.6B}"
export REMOTE_ASR_URL="${REMOTE_ASR_URL:-http://127.0.0.1:18080/asr}"
PYTHON_BIN="${PYTHON_BIN:-$APP_DIR/.venv/bin/python}"
if [ ! -x "$PYTHON_BIN" ]; then
  PYTHON_BIN="$(command -v python3)"
fi

"$PYTHON_BIN" -m uvicorn app:app --uds "$APP_DIR/frontend/meeting.sock"
