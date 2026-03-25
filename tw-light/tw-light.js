(function () {
  var ctx = window.TWL_CONTEXT || {};
  var currentListToken = String(ctx.target || "");
  var currentSurrogate = ctx.initialSurrogate || "";
  var currentPdfCandidates = [];
  var currentPdfCandidateIndex = 0;
  var currentPdfUrl = "";
  var currentPdfPage = 1;
  var currentPdfSupportsPage = false;
  var pdfDoc = null;
  var pdfMode = "single"; // single | continuous
  var pdfRenderId = 0;

  function trimText(v) {
    return String(v || "").replace(/^\s+|\s+$/g, "");
  }

  function getQueryParam(name) {
    var search = String(window.location.search || "");
    if (!search) return "";
    var re = new RegExp("(?:[?&])" + name + "=([^&]*)");
    var m = search.match(re);
    return m && m[1] ? decodeURIComponent(m[1].replace(/\+/g, " ")) : "";
  }

  function byId(id) {
    return document.getElementById(id);
  }

  function getHomePath() {
    var user = trimText(ctx.username || "");
    var token = trimText(currentListToken || ctx.target || "");
    if (user) return "/" + encodeURIComponent(user);
    if (token) return "/" + encodeURIComponent(token);
    return "/";
  }

  function setStatus(text) {
    var el = byId("twlStatus");
    if (el) el.textContent = text;
  }

  function setCurrent(text) {
    var el = byId("twlCurrent");
    if (el) el.textContent = text || "";
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;");
  }

  function xhrGet(url, onSuccess, onError) {
    var xhr = new XMLHttpRequest();
    xhr.open("GET", url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status >= 200 && xhr.status < 300) {
        onSuccess(xhr.responseText, xhr);
      } else {
        if (onError) onError(xhr);
      }
    };
    xhr.send(null);
  }

  function showPanel(target) {
    var textPanel = byId("twlPanelText");
    var pdfPanel = byId("twlPanelPdf");
    var tabText = byId("twlTabText");
    var tabPdf = byId("twlTabPdf");

    if (!textPanel || !pdfPanel || !tabText || !tabPdf) return;

    if (target === "pdf") {
      textPanel.className = "twl-panel";
      pdfPanel.className = "twl-panel active";
      tabText.className = "twl-tab";
      tabPdf.className = "twl-tab active";
    } else {
      textPanel.className = "twl-panel active";
      pdfPanel.className = "twl-panel";
      tabText.className = "twl-tab active";
      tabPdf.className = "twl-tab";
    }
    syncFooterActive(target);
  }

  function syncFooterActive(target) {
    var ft = byId("twlFooterText");
    var fp = byId("twlFooterPdf");
    if (!ft || !fp) return;
    ft.className = target === "pdf" ? "" : "active";
    fp.className = target === "pdf" ? "active" : "";
  }

  function toggleSidebar() {
    var side = document.querySelector(".twl-sidebar");
    if (!side) return;
    if (window.innerWidth > 900) return;
    if (side.className.indexOf("open") !== -1) {
      side.className = side.className.replace(/\s*open\s*/g, " ").replace(/^\s+|\s+$/g, "");
    } else {
      side.className += " open";
    }
  }

  function closeSidebarIfMobile() {
    var side = document.querySelector(".twl-sidebar");
    if (!side) return;
    if (window.innerWidth > 900) return;
    side.className = side.className.replace(/\s*open\s*/g, " ").replace(/^\s+|\s+$/g, "");
  }

  function findPdfUrl(input) {
    var text = String(input || "");
    var m = text.match(/https?:\/\/[^\s\"'<>]+\.pdf(?:\?[^\s\"'<>]*)?/i);
    if (m && m[0]) return m[0];

    var g = text.match(/https?:\/\/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/i);
    if (g && g[1]) return "https://drive.google.com/file/d/" + g[1] + "/preview";

    return "";
  }

  function setPdfSource(url) {
    var frame = byId("twlPdfFrame");
    var link = byId("twlPdfLink");
    var meta = byId("twlPdfMeta");

    if (!frame || !link || !meta) return;

    if (!url) {
      frame.removeAttribute("src");
      link.style.display = "none";
      meta.textContent = "No PDF link found in this item.";
      currentPdfCandidates = [];
      currentPdfCandidateIndex = 0;
      currentPdfUrl = "";
      currentPdfPage = 1;
      currentPdfSupportsPage = false;
      pdfDoc = null;
      updatePdfPageLabel();
      return;
    }

    currentPdfUrl = String(url);
    currentPdfPage = 1;
    currentPdfSupportsPage = /\.pdf($|[?#])/i.test(currentPdfUrl) || /temp_pdf_surrogate-/i.test(currentPdfUrl);
    loadPdfViewer(currentPdfUrl);
    link.href = currentPdfUrl;
    link.style.display = "inline-block";
    meta.textContent = "PDF preview";
  }

  function updatePdfPageLabel() {
    var label = byId("twlPdfPageLabel");
    if (!label) return;
    if (!currentPdfUrl) {
      label.textContent = "Page -";
      return;
    }
    label.textContent = "Page " + String(currentPdfPage);
  }

  function applyPdfPage() {
    var frame = byId("twlPdfFrame");
    var wrap = byId("twlPdfCanvasWrap");
    if (!frame) return;
    if (!currentPdfUrl) {
      frame.removeAttribute("src");
      if (wrap) wrap.innerHTML = "";
      updatePdfPageLabel();
      return;
    }

    var base = currentPdfUrl.split("#")[0];
    if (currentPdfSupportsPage) {
      frame.src = base + "#page=" + String(currentPdfPage);
    } else {
      frame.src = base;
    }
    updatePdfPageLabel();
  }

  function changePdfPage(delta) {
    if (!currentPdfUrl) return;
    if (pdfDoc && pdfMode === "continuous") {
      var nextIdx = currentPdfPage + delta;
      if (nextIdx < 1) nextIdx = 1;
      if (nextIdx > pdfDoc.numPages) nextIdx = pdfDoc.numPages;
      currentPdfPage = nextIdx;
      var wrap = byId("twlPdfCanvasWrap");
      if (wrap) {
        var target = wrap.querySelector('[data-page="' + String(currentPdfPage) + '"]');
        if (target && target.scrollIntoView) target.scrollIntoView({ behavior: "auto", block: "start" });
      }
      updatePdfPageLabel();
      return;
    }
    if (!currentPdfSupportsPage && !pdfDoc) return;
    var next = currentPdfPage + delta;
    if (next < 1) next = 1;
    if (pdfDoc && next > pdfDoc.numPages) next = pdfDoc.numPages;
    currentPdfPage = next;
    if (pdfDoc) renderPdfSinglePage(currentPdfPage);
    else applyPdfPage();
  }

  function setPdfMode(mode) {
    var next = mode === "continuous" ? "continuous" : "single";
    pdfMode = next;
    var btn = byId("twlPdfMode");
    if (btn) btn.textContent = next === "continuous" ? "Single" : "Continuous";
    if (!pdfDoc) return;
    if (next === "continuous") renderPdfContinuous();
    else renderPdfSinglePage(currentPdfPage);
  }

  function setPdfFallback(reason) {
    var frame = byId("twlPdfFrame");
    var wrap = byId("twlPdfCanvasWrap");
    var meta = byId("twlPdfMeta");
    if (wrap) {
      wrap.style.display = "none";
      wrap.innerHTML = "";
    }
    if (frame) {
      frame.style.display = "block";
      applyPdfPage();
    }
    if (meta && reason) {
      if (currentPdfCandidates.length > 1) {
        meta.textContent = "PDF preview (" + reason + ", source " + String(currentPdfCandidateIndex + 1) + "/" + String(currentPdfCandidates.length) + ")";
      } else {
        meta.textContent = "PDF preview (" + reason + ")";
      }
    }
  }

  function getPdfScale(page) {
    var panel = byId("twlPanelPdf");
    var w = panel ? panel.clientWidth : 900;
    var targetW = Math.max(260, Math.min(920, w - 30));
    var vp = page.getViewport({ scale: 1 });
    return targetW / vp.width;
  }

  function renderPdfSinglePage(pageNo) {
    var wrap = byId("twlPdfCanvasWrap");
    var frame = byId("twlPdfFrame");
    if (!pdfDoc || !wrap || !frame) return;
    if (pageNo < 1) pageNo = 1;
    if (pageNo > pdfDoc.numPages) pageNo = pdfDoc.numPages;
    currentPdfPage = pageNo;
    updatePdfPageLabel();

    var rid = ++pdfRenderId;
    wrap.innerHTML = "<div class=\"twl-status\">Loading page " + String(pageNo) + "...</div>";
    wrap.style.display = "block";
    frame.style.display = "none";

    pdfDoc.getPage(pageNo).then(function (page) {
      if (rid !== pdfRenderId) return;
      var scale = getPdfScale(page);
      var vp = page.getViewport({ scale: scale });
      var canvas = document.createElement("canvas");
      canvas.className = "twl-pdf-page";
      canvas.setAttribute("data-page", String(pageNo));
      canvas.width = vp.width;
      canvas.height = vp.height;
      var ctx2d = canvas.getContext("2d");
      wrap.innerHTML = "";
      wrap.appendChild(canvas);
      return page.render({ canvasContext: ctx2d, viewport: vp }).promise;
    }).catch(function () {
      setPdfFallback("fallback");
    });
  }

  function renderPdfContinuous() {
    var wrap = byId("twlPdfCanvasWrap");
    var frame = byId("twlPdfFrame");
    if (!pdfDoc || !wrap || !frame) return;
    var rid = ++pdfRenderId;
    wrap.innerHTML = "<div class=\"twl-status\">Loading pages...</div>";
    wrap.style.display = "block";
    frame.style.display = "none";
    updatePdfPageLabel();

    var i = 1;
    wrap.innerHTML = "";

    function renderNext() {
      if (rid !== pdfRenderId) return;
      if (i > pdfDoc.numPages) return;
      var pageNo = i;
      i += 1;
      pdfDoc.getPage(pageNo).then(function (page) {
        if (rid !== pdfRenderId) return;
        var scale = getPdfScale(page);
        var vp = page.getViewport({ scale: scale });
        var canvas = document.createElement("canvas");
        canvas.className = "twl-pdf-page";
        canvas.setAttribute("data-page", String(pageNo));
        canvas.width = vp.width;
        canvas.height = vp.height;
        var ctx2d = canvas.getContext("2d");
        wrap.appendChild(canvas);
        return page.render({ canvasContext: ctx2d, viewport: vp }).promise;
      }).then(function () {
        if (rid !== pdfRenderId) return;
        renderNext();
      }).catch(function () {
        setPdfFallback("fallback");
      });
    }
    renderNext();
  }

  function loadPdfViewer(url) {
    if (!url) {
      setPdfFallback("");
      return;
    }
    if (!window.pdfjsLib || typeof window.pdfjsLib.getDocument !== "function") {
      setPdfFallback("iframe");
      return;
    }

    try {
      if (window.pdfjsLib.GlobalWorkerOptions) {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = "/assets/pdf.worker.min.js";
      }
    } catch (e) {}

    var loading = window.pdfjsLib.getDocument({ url: url, withCredentials: true, disableWorker: true });
    loading.promise.then(function (doc) {
      pdfDoc = doc;
      currentPdfSupportsPage = true;
      if (currentPdfPage < 1) currentPdfPage = 1;
      if (currentPdfPage > doc.numPages) currentPdfPage = doc.numPages;
      var meta = byId("twlPdfMeta");
      if (meta) {
        if (currentPdfCandidates.length > 1) {
          meta.textContent = "PDF preview (source " + String(currentPdfCandidateIndex + 1) + "/" + String(currentPdfCandidates.length) + ")";
        } else {
          meta.textContent = "PDF preview";
        }
      }
      if (pdfMode === "continuous") renderPdfContinuous();
      else renderPdfSinglePage(currentPdfPage);
    }).catch(function () {
      pdfDoc = null;
      tryNextPdfCandidateOrFallback();
    });
  }

  function tryNextPdfCandidateOrFallback() {
    if (currentPdfCandidates.length > 1 && currentPdfCandidateIndex < currentPdfCandidates.length - 1) {
      currentPdfCandidateIndex += 1;
      currentPdfUrl = currentPdfCandidates[currentPdfCandidateIndex];
      currentPdfPage = 1;
      currentPdfSupportsPage = /\.pdf($|[?#])/i.test(currentPdfUrl) || /temp_pdf_surrogate-/i.test(currentPdfUrl);
      loadPdfViewer(currentPdfUrl);
      var link = byId("twlPdfLink");
      if (link) link.href = currentPdfUrl;
      return;
    }
    setPdfFallback("iframe");
  }

  function uniquePush(arr, value) {
    if (!value) return;
    var i;
    for (i = 0; i < arr.length; i += 1) {
      if (arr[i] === value) return;
    }
    arr.push(value);
  }

  function buildPdfCandidates(owner, surrogate, textUrl) {
    var out = [];
    if (textUrl) {
      uniquePush(out, textUrl);
      if (/^https?:\/\//i.test(textUrl)) {
        uniquePush(out, "/api/proxy.php?url=" + encodeURIComponent(textUrl));
      }
    }
    if (owner && surrogate) {
      var o = encodeURIComponent(owner);
      var s = encodeURIComponent(surrogate);
      var r2Worker = "https://r2-worker.textwhisper.workers.dev/" + o + "/pdf/temp_pdf_surrogate-" + s + ".pdf";
      var r2Public = "https://pub-1afc23a510c147a5a857168f23ff6db8.r2.dev/" + o + "/pdf/temp_pdf_surrogate-" + s + ".pdf";
      uniquePush(out, r2Worker);
      uniquePush(out, r2Public);
      uniquePush(out, "/api/proxy.php?url=" + encodeURIComponent(r2Worker));
      uniquePush(out, "/api/proxy.php?url=" + encodeURIComponent(r2Public));
      uniquePush(out, "/File_getPDF.php?type=pdf&owner=" + o + "&surrogate=" + s);
    }
    return out;
  }

  function setPdfCandidates(candidates) {
    currentPdfCandidates = candidates || [];
    if (!currentPdfCandidates.length) {
      setPdfSource("");
      return;
    }
    currentPdfCandidateIndex = 0;
    setPdfSource(currentPdfCandidates[0]);
    var meta = byId("twlPdfMeta");
    if (meta && currentPdfCandidates.length > 1) {
      meta.textContent = "PDF preview (source 1/" + currentPdfCandidates.length + ")";
    }
  }

  function resolvePdfForItem(surrogate, textHtml) {
    var fromText = findPdfUrl(textHtml || "");
    var s = String(surrogate || "");
    if (!s) {
      setPdfCandidates(buildPdfCandidates("", "", fromText));
      return;
    }

    xhrGet("/getItemMeta.php?surrogate=" + encodeURIComponent(s), function (body) {
      var owner = "";
      try {
        var data = JSON.parse(body || "{}");
        if (data && data.status === "success" && data.owner) owner = String(data.owner);
      } catch (e) {}
      setPdfCandidates(buildPdfCandidates(owner, s, fromText));
    }, function () {
      setPdfCandidates(buildPdfCandidates("", s, fromText));
    });
  }

  function markActiveTreeToken(token) {
    var nodes = document.querySelectorAll(".twl-tree-head");
    var i;
    for (i = 0; i < nodes.length; i += 1) {
      if (nodes[i].getAttribute("data-token") === token) {
        nodes[i].className = "twl-tree-head active";
      } else {
        nodes[i].className = "twl-tree-head";
      }
    }
  }

  function markActiveItem(surrogate) {
    var nodes = document.querySelectorAll("#twlItemList .list-sub-item, .twl-inline-item");
    var i;
    for (i = 0; i < nodes.length; i += 1) {
      if (String(nodes[i].getAttribute("data-value")) === String(surrogate)) {
        if (String(nodes[i].className).indexOf("twl-inline-item") !== -1) {
          nodes[i].className = "twl-inline-item active";
        } else {
          nodes[i].className = "list-sub-item active";
        }
      } else {
        if (String(nodes[i].className).indexOf("twl-inline-item") !== -1) {
          nodes[i].className = "twl-inline-item";
        } else {
          nodes[i].className = "list-sub-item";
        }
      }
    }
  }

  function selectItem(surrogate, token) {
    currentSurrogate = String(surrogate || "");
    if (token) currentListToken = String(token);

    markActiveItem(currentSurrogate);
    setCurrent(currentListToken + " / " + currentSurrogate);

    xhrGet("/getText.php?q=" + encodeURIComponent(currentSurrogate), function (html, xhr) {
      var textView = byId("twlTextView");
      var meta = byId("twlTextMeta");
      if (textView) textView.innerHTML = html || "";
      if (meta) {
        var owner = xhr.getResponseHeader("X-Text-Owner") || "";
        var updated = xhr.getResponseHeader("X-Text-Updated-Time") || "";
        meta.textContent = owner ? ("Owner: " + owner + (updated ? (" | Updated: " + updated) : "")) : "";
      }
      resolvePdfForItem(currentSurrogate, html || "");
      closeSidebarIfMobile();
    }, function () {
      var textView = byId("twlTextView");
      if (textView) textView.textContent = "Could not load text.";
      setPdfSource("");
    });
  }

  window.selectItem = function (surrogate, token) {
    selectItem(surrogate, token);
  };

  // getListItems.php uses this on "All Content" year groups.
  window.toggleYearGroup = function (el) {
    if (!el) return;
    var items = el.nextElementSibling;
    if (!items) return;
    var arrow = el.querySelector(".year-arrow");
    var open = items.style.display !== "none";
    items.style.display = open ? "none" : "block";
    if (arrow) arrow.textContent = open ? "▶" : "▼";
  };

  function bindItemListSelection() {
    var container = byId("twlItemList");
    if (!container) return;

    container.addEventListener("click", function (e) {
      var node = e.target;
      while (node && node !== container && !(node.className && String(node.className).indexOf("list-sub-item") !== -1)) {
        node = node.parentNode;
      }
      if (!node || node === container) return;

      var surrogate = node.getAttribute("data-value");
      var token = node.getAttribute("data-token") || currentListToken;
      if (!surrogate) return;
      selectItem(surrogate, token);
    });
  }

  function loadListItems(token) {
    currentListToken = String(token || "");
    if (!currentListToken) return;

    markActiveTreeToken(currentListToken);
    setCurrent(currentListToken);

    var url = "/getListItems.php?list=" + encodeURIComponent(currentListToken);
    xhrGet(url, function (html) {
      var itemList = byId("twlItemList");
      if (itemList) itemList.innerHTML = html;

      if (currentSurrogate) {
        var selector = '.list-sub-item[data-value="' + currentSurrogate + '"]';
        var exists = itemList ? itemList.querySelector(selector) : null;
        if (exists) {
          selectItem(currentSurrogate, currentListToken);
          return;
        }
      }

      var first = itemList ? itemList.querySelector(".list-sub-item[data-value]") : null;
      if (first) {
        selectItem(first.getAttribute("data-value"), currentListToken);
      }
      closeSidebarIfMobile();
    }, function () {
      var itemList = byId("twlItemList");
      if (itemList) itemList.innerHTML = "<div class=\"twl-status\">Could not load items.</div>";
    });
  }

  function renderInlineItems(container, html, token) {
    if (!container) return 0;
    container.innerHTML = "";

    var tmp = document.createElement("div");
    tmp.innerHTML = html || "";
    var rows = tmp.querySelectorAll(".list-sub-item[data-value]");
    if (!rows || !rows.length) {
      container.innerHTML = "<div class=\"twl-status\">(empty)</div>";
      return 0;
    }

    var wrap = document.createElement("div");
    wrap.className = "twl-inline-items";

    var i;
    for (i = 0; i < rows.length; i += 1) {
      var row = rows[i];
      var surrogate = row.getAttribute("data-value") || "";
      if (!surrogate) continue;

      var titleEl = row.querySelector(".item-subject");
      var ownerEl = row.querySelector(".item-owner");
      var title = titleEl ? titleEl.textContent : (row.textContent || "").trim();
      var owner = ownerEl ? ownerEl.textContent : "";

      var item = document.createElement("div");
      item.className = "twl-inline-item";
      item.setAttribute("data-value", surrogate);
      item.setAttribute("data-token", token);

      var t = document.createElement("div");
      t.className = "twl-inline-item-title";
      t.appendChild(document.createTextNode(title || ("Item " + surrogate)));

      var m = document.createElement("div");
      m.className = "twl-inline-item-meta";
      m.appendChild(document.createTextNode(owner || ""));

      item.appendChild(t);
      if (owner) item.appendChild(m);

      item.addEventListener("click", function (e) {
        if (e && e.stopPropagation) e.stopPropagation();
        var s = this.getAttribute("data-value");
        var tk = this.getAttribute("data-token") || token;
        if (s) selectItem(s, tk);
      });

      wrap.appendChild(item);
    }

    container.appendChild(wrap);
    return wrap.children.length;
  }

  function resolveNodeToken(list) {
    if (!list) return "";
    var rel = String(list.relationship || "");
    if (rel === "inviter_group") {
      return String(list.owner_username || list.token || "");
    }
    if (rel === "invited_group" || rel === "followed_group") {
      return "";
    }
    return String(list.token || "");
  }

  function makeTreeNode(list, depth) {
    if (typeof depth !== "number") depth = 0;
    var hasChildren = !!(list.children && list.children.length);
    var defaultOpen = hasChildren && depth === 0;
    var wrap = document.createElement("div");
    wrap.className = "twl-tree-node";
    var nodeToken = resolveNodeToken(list);

    var head = document.createElement("div");
    head.className = "twl-tree-head";
    head.setAttribute("data-token", nodeToken);

    var arrow = document.createElement("span");
    arrow.className = "twl-tree-arrow";
    arrow.appendChild(document.createTextNode(hasChildren ? (defaultOpen ? "▾" : "▸") : "•"));

    var label = document.createElement("span");
    label.appendChild(document.createTextNode(list.title || list.name || list.token || "list"));

    head.appendChild(arrow);
    head.appendChild(label);
    wrap.appendChild(head);

    var childrenWrap = document.createElement("div");
    childrenWrap.className = "twl-tree-children";
    childrenWrap.style.display = defaultOpen ? "block" : "none";
    var inlineLoaded = false;
    var inlineLoading = false;

    if (hasChildren) {
      var i;
      for (i = 0; i < list.children.length; i += 1) {
        childrenWrap.appendChild(makeTreeNode(list.children[i], depth + 1));
      }
    }
    wrap.appendChild(childrenWrap);

    head.addEventListener("click", function () {
      if (hasChildren) {
        var open = childrenWrap.style.display !== "none";
        childrenWrap.style.display = open ? "none" : "block";
        arrow.firstChild.nodeValue = open ? "▸" : "▾";
        return;
      }

      if (!nodeToken) return;

      var isOpen = childrenWrap.style.display !== "none";
      if (isOpen) {
        childrenWrap.style.display = "none";
        arrow.firstChild.nodeValue = "▸";
        return;
      }

      childrenWrap.style.display = "block";
      arrow.firstChild.nodeValue = "▾";

      if (inlineLoaded || inlineLoading) {
        if (currentListToken !== nodeToken) currentListToken = nodeToken;
        return;
      }

      inlineLoading = true;
      childrenWrap.innerHTML = "<div class=\"twl-status\">Loading items...</div>";
      xhrGet("/getListItems.php?list=" + encodeURIComponent(nodeToken), function (html) {
        inlineLoading = false;
        inlineLoaded = true;
        renderInlineItems(childrenWrap, html, nodeToken);
        currentListToken = nodeToken;
      }, function () {
        inlineLoading = false;
        childrenWrap.innerHTML = "<div class=\"twl-status\">Could not load items.</div>";
      });

      if (nodeToken && !hasChildren) {
        markActiveTreeToken(nodeToken);
        setCurrent(nodeToken);
      }
    });

    return wrap;
  }

  function appendGroup(root, title, listArr) {
    if (!listArr || !listArr.length) return;

    var groupLabel = document.createElement("div");
    groupLabel.className = "twl-group-label";
    groupLabel.appendChild(document.createTextNode(title));
    root.appendChild(groupLabel);

    var i;
    for (i = 0; i < listArr.length; i += 1) {
      root.appendChild(makeTreeNode(listArr[i], 0));
    }
  }

  function buildFallbackTree(root) {
    if (!root) return false;
    if (root.querySelector(".twl-tree-node")) return true;
    if (!trimText(currentListToken)) return false;

    var fallback = {
      token: currentListToken,
      title: currentListToken,
      children: []
    };

    var label = document.createElement("div");
    label.className = "twl-group-label";
    label.appendChild(document.createTextNode("Lists"));
    root.appendChild(label);
    root.appendChild(makeTreeNode(fallback, 0));
    return true;
  }

  function loadTree() {
    if (!trimText(currentListToken)) {
      setStatus("Enter a profile/list token and press Open.");
      return;
    }

    setStatus("Loading lists...");

    xhrGet("/getOwnersListsJSON.php?token=" + encodeURIComponent(currentListToken), function (text) {
      var data;
      try {
        data = JSON.parse(text || "{}");
      } catch (e) {
        setStatus("List data parse failed.");
        return;
      }

      var root = byId("twlListTree");
      if (!root) return;
      root.innerHTML = "";

      if (!data || data.error) {
        setStatus(data && data.error ? data.error : "Could not load lists.");
        return;
      }

      appendGroup(root, "Owned", data.owned || []);

      var invited = [];
      var followed = [];
      var accessible = data.accessible || [];
      var i;
      for (i = 0; i < accessible.length; i += 1) {
        var rel = String(accessible[i].relationship || "");
        if (rel === "invited_group") {
          invited = accessible[i].children || [];
        } else if (rel === "followed_group") {
          followed = accessible[i].children || [];
        }
      }

      appendGroup(root, "Invited", invited);
      appendGroup(root, "Followed", followed);

      if (!buildFallbackTree(root)) {
        setStatus("No lists available for this token.");
        return;
      }

      setStatus("Lists loaded");

      if (data.owned && data.owned.length && data.owned[0].token) {
        if (!trimText(currentListToken) || currentListToken === trimText(ctx.target || "")) {
          currentListToken = data.owned[0].token;
        }
      }

      if (currentListToken) {
        loadListItems(currentListToken);
      }
    }, function () {
      setStatus("Could not load lists.");
    });
  }

  function initTokenForm() {
    var form = byId("twlTokenForm");
    var input = byId("twlTokenInput");
    if (!form || !input) return;

    var queryToken = trimText(getQueryParam("token"));
    if (!trimText(currentListToken) && queryToken) {
      currentListToken = queryToken;
      input.value = queryToken;
    } else if (!trimText(input.value) && trimText(currentListToken)) {
      input.value = currentListToken;
    }

    form.addEventListener("submit", function (e) {
      if (e && e.preventDefault) e.preventDefault();
      var token = trimText(input.value);
      if (!token) {
        setStatus("Please enter a token.");
        return;
      }
      var next = "/" + encodeURIComponent(token);
      window.location.href = next;
    });
  }

  function initTabs() {
    var t = byId("twlTabText");
    var p = byId("twlTabPdf");
    var m = byId("twlToggleSidebar");
    var fs = byId("twlFooterSidebar");
    var ft = byId("twlFooterText");
    var fp = byId("twlFooterPdf");
    if (t) {
      t.addEventListener("click", function () {
        showPanel("text");
      });
    }
    if (p) {
      p.addEventListener("click", function () {
        showPanel("pdf");
      });
    }
    if (m) {
      m.addEventListener("click", toggleSidebar);
    }
    if (fs) {
      fs.addEventListener("click", toggleSidebar);
    }
    if (ft) {
      ft.addEventListener("click", function () { showPanel("text"); });
    }
    if (fp) {
      fp.addEventListener("click", function () { showPanel("pdf"); });
    }
  }

  function initPdfPaging() {
    var prev = byId("twlPdfPrev");
    var next = byId("twlPdfNext");
    var mode = byId("twlPdfMode");
    if (prev) {
      prev.addEventListener("click", function () { changePdfPage(-1); });
    }
    if (next) {
      next.addEventListener("click", function () { changePdfPage(1); });
    }
    if (mode) {
      mode.addEventListener("click", function () {
        setPdfMode(pdfMode === "continuous" ? "single" : "continuous");
      });
    }

    var panel = byId("twlPanelPdf");
    if (!panel) return;
    var startX = null;

    panel.addEventListener("touchstart", function (e) {
      if (!e.touches || !e.touches[0]) return;
      startX = e.touches[0].clientX;
    }, { passive: true });

    panel.addEventListener("touchend", function (e) {
      if (startX === null) return;
      var t = e.changedTouches && e.changedTouches[0] ? e.changedTouches[0] : null;
      if (!t) {
        startX = null;
        return;
      }
      var dx = t.clientX - startX;
      startX = null;
      if (Math.abs(dx) < 40) return;
      if (dx < 0) changePdfPage(1);
      else changePdfPage(-1);
    }, { passive: true });
  }

  function initLayout() {
    if (window.innerWidth <= 900) {
      var side = document.querySelector(".twl-sidebar");
      if (side && side.className.indexOf("open") === -1) {
        side.className += " open";
      }
    }
  }

  function init() {
    initLayout();
    initTokenForm();
    initTabs();
    initPdfPaging();
    bindItemListSelection();
    loadTree();
    updatePdfPageLabel();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
