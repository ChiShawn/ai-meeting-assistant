(() => {
  const $ = (sel) => document.querySelector(sel);

  // ---------- 容錯時間轉換 / 片段正規化 ----------
  function toHMSAny(v) {
    if (v === undefined || v === null) return null;
    if (typeof v === "number") {
      const s = Math.max(0, Math.round(v));
      const hh = String(Math.floor(s / 3600)).padStart(2, "0");
      const mm = String(Math.floor((s % 3600) / 60)).padStart(2, "0");
      const ss = String(s % 60).padStart(2, "0");
      return `${hh}:${mm}:${ss}`;
    }
    const s = String(v).trim();
    if (!s) return null;
    if (/^\d+(\.\d+)?$/.test(s)) {
      return toHMSAny(parseFloat(s)); // 秒
    }
    if (/^\d{4,}$/.test(s)) {
      return toHMSAny(parseFloat(s) / 1000.0); // 毫秒
    }
    if (/^\d{1,2}:\d{2}(:\d{2})?(\.\d+)?$/.test(s)) {
      const base = s.split(".")[0];
      const parts = base.split(":");
      if (parts.length === 2) {
        const [h, m] = parts.map(x => parseInt(x, 10));
        return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:00`;
      }
      if (parts.length === 3) {
        const [h, m, ss] = parts.map(x => parseInt(x, 10));
        return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:${String(ss).padStart(2, "0")}`;
      }
    }
    return null;
  }

  function normalizeSegmentsAny(list) {
    if (!Array.isArray(list)) return [];
    const sKeys = ["start", "start_time", "stime", "from", "tStartMs", "ts", "begin", "s", "startTime", "offsetStart", "t0"];
    const eKeys = ["end", "end_time", "etime", "to", "tEndMs", "te", "finish", "e", "endTime", "offsetEnd", "t1"];
    const tKeys = ["text", "sentence", "value", "transcript", "utterance", "content", "msg", "message"];
    const out = [];
    for (const it of list) {
      if (typeof it !== "object" || it === null) continue;
      let rs = null, re = null, tx = "";
      for (const k of sKeys) if (k in it) { rs = it[k]; break; }
      for (const k of eKeys) if (k in it) { re = it[k]; break; }
      for (const k of tKeys) if (k in it) { tx = String(it[k] ?? "").trim(); if (tx) break; }
      const sh = toHMSAny(rs);
      const eh = toHMSAny(re);
      if (sh && eh) out.push({ start: sh, end: eh, text: tx });
    }
    return out;
  }

  // ---------- DOM / 狀態 ----------
  const els = {
    file: $("#audioFile"),
    backendSel: $("#backendSel"),
    btnStart: $("#btnStart"),
    barFill: $("#barFill"),
    barPct: $("#barPct"),
    status: $("#statusText"),

    rawText: $("#rawText"),
    llmText: $("#llmText"),

    btnLLMClean: $("#btnLLMClean"),
    btnSummaryRaw: $("#btnSummaryRaw"),
    btnTodoRaw: $("#btnTodoRaw"),
    btnSummaryClean: $("#btnSummaryClean"),
    btnTodoClean: $("#btnTodoClean"),

    summaryLen: $("#summaryLen"),
    summaryText: $("#summaryText"),
    todoText: $("#todoText"),
  };

  let pollTimer = null;
  let currentGuid = null;
  let rawSegments = [];      // [{start,end,text}]
  let cleanedSegments = [];  // [{start,end,text}]

  // ---------- 小工具 ----------
  function setProgress(pct, statusText) {
    const v = Math.max(0, Math.min(100, Math.floor(pct || 0)));
    if (els.barFill) els.barFill.style.width = `${v}%`;
    if (els.barPct) els.barPct.textContent = `${v}%`;
    if (statusText && els.status) els.status.textContent = statusText;
  }

  function decodeBackendValue() {
    const val = (els.backendSel && els.backendSel.value) ? els.backendSel.value : "auto|auto";
    const [backend, model_size] = val.split("|");
    return { backend, model_size };
  }

  function segsToLines(segments) {
    return segments.map(s => `[${s.start} - ${s.end}] ${s.text}`).join("\n");
  }

  function segmentsToText(segments) {
    return segments.map(s => s.text).join("");
  }

  function chooseTextFor() {
    if (cleanedSegments.length > 0) return segmentsToText(cleanedSegments);
    if (rawSegments.length > 0) return segmentsToText(rawSegments);
    const t = (els.llmText?.value || els.rawText?.value || "").trim();
    return t;
  }

  // ---------- 主流程 ----------
  async function startTranscribe() {
    const f = els.file?.files?.[0];
    if (!f) { alert("請先選擇音檔！"); return; }
    const { backend, model_size } = decodeBackendValue();

    // reset ui
    if (els.rawText) els.rawText.value = "";
    if (els.llmText) els.llmText.value = "";
    if (els.summaryText) els.summaryText.value = "";
    if (els.todoText) els.todoText.value = "";
    rawSegments = [];
    cleanedSegments = [];
    setProgress(0, "上傳中…");
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    if (els.btnStart) els.btnStart.disabled = true;

    // send
    const fd = new FormData();
    fd.append("file", f);
    fd.append("backend", backend);
    fd.append("model_size", model_size);
    fd.append("language", "zh");

    let taskId = null;
    try {
      const r = await fetch("proxy.php?path=/transcribe_async", { method: "POST", body: fd });
      if (!r.ok) throw new Error(await r.text());
      const j = await r.json();
      taskId = j.task_id ?? j.taskId ?? j.guid ?? j.id;
      if (!taskId) throw new Error("Server 未回傳任務 ID");
      currentGuid = taskId;
    } catch (err) {
      console.error(err);
      alert("發送轉寫請求失敗：" + err);
      if (els.btnStart) els.btnStart.disabled = false;
      return;
    }

    // poll
    setProgress(5, "排隊中…");
    pollTimer = setInterval(async () => {
      try {
        const r = await fetch(`proxy.php?path=/task/${taskId}`);
        if (r.status === 404) return; // 可能還沒建好
        const j = await r.json();

        setProgress(j.progress || 0, `狀態：${j.status || "未知"}${j.error ? `（${j.error}）` : ""}`);

        if (j.status === "failed" || j.status === "error") {
          clearInterval(pollTimer); pollTimer = null;
          if (els.btnStart) els.btnStart.disabled = false;
          alert("轉寫失敗：" + (j.error || "未知錯誤"));
          return;
        }

        if (j.status === "done") {
          clearInterval(pollTimer); pollTimer = null;
          if (els.btnStart) els.btnStart.disabled = false;

          const result = j.result ?? { text: j.text, segments: j.segments };
          let segs = Array.isArray(result.segments) ? result.segments : [];
          let text = (result.text || "").trim();

          // 前端保險：正規化時間戳
          segs = normalizeSegmentsAny(segs);

          if ((!text || text.length === 0) && segs.length > 0) {
            text = segmentsToText(segs);
          }
          rawSegments = segs;

          if (segs.length > 0 && els.rawText) {
            els.rawText.value = segsToLines(segs);
          } else if (els.rawText) {
            els.rawText.value = text;
          }

          setProgress(100, "完成");

          // CRITICAL: Delete the task from the server memory to ensure no files or transcripts are retained 
          fetch(`proxy.php?path=/task/${taskId}`, { method: 'DELETE' }).catch(e => console.error(e));
        }
      } catch (err) {
        console.error(err);
      }
    }, 800);
  }

  async function doLLMClean() {
    if (!els.btnLLMClean) return;
    if (rawSegments.length === 0 && !(els.rawText && els.rawText.value.trim())) {
      alert("還沒有逐字稿可整理，請先轉寫。");
      return;
    }

    let segs = rawSegments;
    if (segs.length === 0 && els.rawText) {
      const lines = els.rawText.value.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
      let t = 0;
      segs = lines.map(line => {
        const st = new Date(t * 1000).toISOString().substr(11, 8);
        t += 3;
        const ed = new Date(t * 1000).toISOString().substr(11, 8);
        return { start: st, end: ed, text: line.replace(/^\[\d+:\d+(?::\d+)?\s*-\s*\d+:\d+(?::\d+)?\]\s*/, "") };
      });
    }

    els.btnLLMClean.disabled = true;
    els.btnLLMClean.textContent = "整理中…";

    try {
      const r = await fetch("proxy.php?path=/llm_clean", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ segments: segs }),
      });
      if (!r.ok) throw new Error(await r.text());
      const j = await r.json();

      cleanedSegments = Array.isArray(j.cleaned_segments) ? j.cleaned_segments : [];
      if (cleanedSegments.length > 0 && els.llmText) {
        els.llmText.value = cleanedSegments.map(s => `[${s.start} - ${s.end}] ${s.text}`).join("\n");
      } else if (els.llmText) {
        els.llmText.value = "[整理後無輸出]";
      }
    } catch (err) {
      console.error(err);
      alert("vLLM 整理失敗：" + err);
    } finally {
      els.btnLLMClean.disabled = false;
      els.btnLLMClean.textContent = "vLLM 整理";
    }
  }

  async function doSummarize(useCleaned) {
    const btn = useCleaned ? els.btnSummaryClean : els.btnSummaryRaw;
    if (!btn) return;

    let text = "";
    if (useCleaned) {
      text = chooseTextFor();
      if (!text) return alert("沒有可用的潤飾稿 / 原稿內容。");
    } else {
      if (rawSegments.length > 0) text = segmentsToText(rawSegments);
      else text = (els.rawText?.value || "").trim();
      if (!text) return alert("沒有可用的原始逐字稿。");
    }

    const summary_length = els.summaryLen ? els.summaryLen.value : "medium";
    const payload = { text_to_summarize: text, summary_length };

    btn.disabled = true; btn.textContent = "摘要中…";

    try {
      const r = await fetch("proxy.php?path=/summarize", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      if (!r.ok) throw new Error(await r.text());
      const j = await r.json();
      if (els.summaryText) els.summaryText.value = (j.summary_text || "").trim();
    } catch (err) {
      console.error(err);
      alert("產生摘要失敗：" + err);
    } finally {
      btn.disabled = false; btn.textContent = useCleaned ? "（潤飾）產生摘要" : "（原稿）產生摘要";
    }
  }

  async function doTodo(useCleaned) {
    const btn = useCleaned ? els.btnTodoClean : els.btnTodoRaw;
    if (!btn) return;

    let text = "";
    if (useCleaned) {
      text = chooseTextFor();
      if (!text) return alert("沒有可用的潤飾稿 / 原稿內容。");
    } else {
      if (rawSegments.length > 0) text = segmentsToText(rawSegments);
      else text = (els.rawText?.value || "").trim();
      if (!text) return alert("沒有可用的原始逐字稿。");
    }

    const payload = { text_to_analyze: text };
    btn.disabled = true; btn.textContent = "擷取中…";

    try {
      const r = await fetch("proxy.php?path=/todo", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      if (!r.ok) throw new Error(await r.text());
      const j = await r.json();
      if (els.todoText) els.todoText.value = (j.todo_list || "").trim();
    } catch (err) {
      console.error(err);
      alert("擷取待辦失敗：" + err);
    } finally {
      btn.disabled = false; btn.textContent = useCleaned ? "（潤飾）產生待辦" : "（原稿）產生待辦";
    }
  }

  // ---------- 綁定事件 ----------
  els?.btnStart && els.btnStart.addEventListener("click", startTranscribe);
  els?.btnLLMClean && els.btnLLMClean.addEventListener("click", doLLMClean);
  els?.btnSummaryRaw && els.btnSummaryRaw.addEventListener("click", () => doSummarize(false));
  els?.btnSummaryClean && els.btnSummaryClean.addEventListener("click", () => doSummarize(true));
  els?.btnTodoRaw && els.btnTodoRaw.addEventListener("click", () => doTodo(false));
  els?.btnTodoClean && els.btnTodoClean.addEventListener("click", () => doTodo(true));
})();
