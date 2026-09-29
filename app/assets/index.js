const toastEl = () => document.getElementById("toast");
const showToast = (message) => {
    const toast = toastEl();
    if (!toast) return;
    toast.textContent = message;
    toast.hidden = false;
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => toast.hidden = true, 1800);
};
const formActionUrl = (form) => {
    if (!form) return window.location.href;
    const attr = typeof form.getAttribute === "function" ? form.getAttribute("action") : null;
    if (typeof attr === "string" && attr !== "") return attr;
    try {
        const prop = form.action;
        if (typeof prop === "string" && prop !== "") return prop;
    } catch (_) {}
    return window.location.href;
};
window.formActionUrl = formActionUrl;
const markButtonPending = button => {
    if (!button) return;
    button.classList.add("is-click-pending");
    button.setAttribute("aria-busy", "true");
};
window.addEventListener("pageshow", () => {
    document.querySelectorAll(".is-click-pending").forEach(button => {
        button.classList.remove("is-click-pending");
        button.removeAttribute("aria-busy");
    });
});
const modal = document.getElementById("notify-modal");
const modalBody = document.getElementById("notify-modal-body");
const modalTitle = document.getElementById("notify-modal-title");
let confirmResolve = null;
let modalReturnFocus = null;
const rememberModalFocus = () => {
    if (!modal || !modal.hidden) return;
    modalReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
};
const restoreModalFocus = () => {
    const target = modalReturnFocus;
    modalReturnFocus = null;
    if (target && target.isConnected) target.focus();
};
const closeModal = () => {
    if (confirmResolve) {
        const resolve = confirmResolve;
        confirmResolve = null;
        resolve(false);
    }
    if (modal) modal.hidden = true;
    if (modalBody) modalBody.innerHTML = "";
    restoreModalFocus();
};
const openModal = (title, html) => {
    if (!modal || !modalBody) return;
    rememberModalFocus();
    if (modalTitle) modalTitle.textContent = title;
    modalBody.innerHTML = html;
    modal.hidden = false;
};
const modalFocusables = () => modal
    ? Array.from(modal.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')).filter(el => el.offsetParent !== null)
    : [];
// 把 Tab 焦点锁在弹层内，键盘用户不会跑到背后的页面上
document.addEventListener("keydown", e => {
    if (!modal || modal.hidden) return;
    if (e.key === "Escape") {
        e.preventDefault();
        closeModal();
        return;
    }
    if (e.key !== "Tab") return;
    const items = modalFocusables();
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;
    if (e.shiftKey && (active === first || !modal.contains(active))) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && (active === last || !modal.contains(active))) {
        e.preventDefault();
        first.focus();
    }
});
const mobileMenu = document.getElementById("mobile-menu");
const mobileMenuOpen = document.querySelector("[data-mobile-menu-open]");
const closeMobileMenu = () => {
    if (!mobileMenu) return;
    mobileMenu.hidden = true;
    document.body.classList.remove("mobile-menu-open");
    if (mobileMenuOpen) mobileMenuOpen.setAttribute("aria-expanded", "false");
};
const openMobileMenu = async () => {
    if (!mobileMenu) return;
    mobileMenu.hidden = false;
    document.body.classList.add("mobile-menu-open");
    if (mobileMenuOpen) mobileMenuOpen.setAttribute("aria-expanded", "true");
    const body = mobileMenu.querySelector(".mobile-menu-body");
    if (!body || body.dataset.loaded) return;
    body.dataset.loaded = "loading";
    body.textContent = "加载中…";
    try {
        // 把当前页地址带给片段端点：抽屉里的语言切换链接需要以当前页为跳回目标
        const menuUrl = mobileMenu.dataset.mobileMenuUrl;
        const back = encodeURIComponent(location.pathname + location.search);
        const menuUrlWithBack = menuUrl + (menuUrl.includes("?") ? "&" : "?") + "back=" + back;
        const response = await fetch(menuUrlWithBack, {headers: {"X-Requested-With": "XMLHttpRequest"}});
        if (!response.ok) throw new Error();
        body.innerHTML = await response.text();
        body.dataset.loaded = "1";
    } catch {
        delete body.dataset.loaded;
        body.textContent = "加载失败，请重新打开菜单";
    }
};
if (mobileMenuOpen) mobileMenuOpen.addEventListener("click", openMobileMenu);
document.addEventListener("click", e => {
    const button = e.target instanceof Element ? e.target.closest("[data-profile-toggle]") : null;
    if (!button) return;
    const disclosure = button.closest("[data-profile-disclosure]");
    const detail = disclosure?.querySelector("[data-profile-edit]");
    if (!detail) return;
    const open = detail.classList.contains("is-hidden");
    detail.classList.toggle("is-hidden", !open);
    button.setAttribute("aria-expanded", open ? "true" : "false");
    if (open) detail.querySelector("input, select, textarea")?.focus();
});
const forumMoreToggle = document.querySelector("[data-forum-more-toggle]");
const forumMoreRegion = document.getElementById("forum-more-region");
if (forumMoreToggle && forumMoreRegion) {
    forumMoreToggle.addEventListener("click", () => {
        const open = forumMoreRegion.hidden;
        forumMoreRegion.hidden = !open;
        forumMoreToggle.setAttribute("aria-expanded", open ? "true" : "false");
    });
}
document.addEventListener("click", e => {
    const target = e.target instanceof Element ? e.target : null;
    if (target && target.closest("[data-mobile-menu-close]")) {
        closeMobileMenu();
        return;
    }
    if (mobileMenu && !mobileMenu.hidden && target === mobileMenu) closeMobileMenu();
});
document.addEventListener("keydown", e => {
    if (e.key === "Escape" && (!modal || modal.hidden)) closeMobileMenu();
});
const finishConfirm = (ok) => {
    const resolve = confirmResolve;
    confirmResolve = null;
    if (modal) modal.hidden = true;
    if (modalBody) modalBody.innerHTML = "";
    restoreModalFocus();
    if (resolve) resolve(ok);
};
const _openModalBox = (title, fallback, builderFn) => new Promise(resolve => {
    if (!modal || !modalBody) { resolve(fallback); return; }
    if (confirmResolve) {
        const previous = confirmResolve;
        confirmResolve = null;
        previous(false);
    }
    rememberModalFocus();
    confirmResolve = resolve;
    if (modalTitle) modalTitle.textContent = title;
    modalBody.innerHTML = "";
    const box = document.createElement("div");
    const cancel = document.createElement("button");
    cancel.type = "button";
    cancel.className = "btn alt";
    cancel.textContent = "取消";
    const ok = document.createElement("button");
    ok.type = "button";
    ok.className = "btn danger";
    const focusEl = builderFn(box, cancel, ok);
    modalBody.appendChild(box);
    modal.hidden = false;
    focusEl.focus();
    if (focusEl === ok || focusEl === cancel) return;
    if (focusEl.select) focusEl.select();
});
const openConfirm = (message, title = "确认操作") => _openModalBox(title, false, (box, cancel, ok) => {
    box.className = "confirm-box";
    const text = document.createElement("p");
    text.className = "confirm-message";
    text.textContent = message;
    const actions = document.createElement("div");
    actions.className = "confirm-actions";
    cancel.addEventListener("click", () => finishConfirm(false));
    ok.textContent = "确定";
    ok.addEventListener("click", () => finishConfirm(true));
    actions.append(cancel, ok);
    box.append(text, actions);
    return cancel;
});
const openPrompt = (message, title = "请输入", value = "1") => _openModalBox(title, null, (box, cancel, ok) => {
    box.className = "confirm-box prompt-box";
    const text = document.createElement("p");
    text.className = "confirm-message";
    text.textContent = message;
    const input = document.createElement("input");
    input.className = "prompt-input";
    input.type = "number";
    input.min = "1";
    input.step = "1";
    input.value = String(value || "1");
    const actions = document.createElement("div");
    actions.className = "confirm-actions";
    const done = () => { finishConfirm(String(Math.max(1, parseInt(input.value || "1", 10) || 1))); };
    cancel.addEventListener("click", () => finishConfirm(null));
    ok.textContent = "确定";
    ok.addEventListener("click", done);
    input.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); done(); } });
    actions.append(cancel, ok);
    box.append(text, input, actions);
    return input;
});
const runPageFlash = () => {
    if (window.__pageFlash) showToast(window.__pageFlash);
};
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", runPageFlash);
else runPageFlash();
const initTabBarWrap = () => {
    const bars = Array.from(document.querySelectorAll(".tab-bar"));
    if (!bars.length) return;
    const refresh = bar => {
        const tabs = Array.from(bar.querySelectorAll(":scope > .tab"));
        tabs.forEach(tab => tab.classList.remove("tab-wrapped", "tab-line-start", "tab-line-end"));
        const first = tabs[0];
        if (!first) return;
        const firstTop = first.offsetTop;
        tabs.forEach((tab, index) => {
            const wrapped = tab.offsetTop > firstTop + 1;
            const previous = tabs[index - 1];
            const next = tabs[index + 1];
            tab.classList.toggle("tab-wrapped", wrapped);
            tab.classList.toggle("tab-line-start", Boolean(previous && tab.offsetTop > previous.offsetTop + 1));
            tab.classList.toggle("tab-line-end", !next || next.offsetTop > tab.offsetTop + 1);
        });
    };
    let queued = false;
    const schedule = () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            bars.forEach(refresh);
        });
    };
    schedule();
    window.addEventListener("resize", schedule);
    window.addEventListener("load", schedule, { once: true });
    document.fonts?.ready?.then(schedule);
    if ("ResizeObserver" in window) {
        const observer = new ResizeObserver(schedule);
        bars.forEach(bar => observer.observe(bar));
    }
};
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initTabBarWrap);
else initTabBarWrap();
document.addEventListener("change", e => {
    const action = e.target.closest("[data-topic-action]");
    if (!action) return;
    const form = action.closest("form");
    form?.querySelectorAll("[data-topic-action-secondary]").forEach(field => {
        field.classList.toggle("is-hidden", field.dataset.topicActionSecondary !== action.value);
    });
});
document.addEventListener("change", e => {
    const select = e.target.closest("select[data-reply-actions]");
    if (!select) return;
    const form = select.closest("form");
    if (form) form.dataset.confirm = select.options[select.selectedIndex]?.dataset?.confirm || "";
});
document.addEventListener("click", e => {
    const swatch = e.target.closest("[data-topic-color]");
    if (!swatch) return;
    const wrap = swatch.closest("[data-topic-highlight-wrap]");
    const form = swatch.closest("form");
    const input = form?.querySelector("[data-topic-highlight-value]");
    if (!input || !wrap) return;
    input.value = swatch.dataset.topicColor || "";
    wrap.querySelectorAll("[data-topic-color]").forEach(btn => btn.classList.toggle("active", btn === swatch));
});
window.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("[data-topic-action]").forEach(action => {
        action.dispatchEvent(new Event("change", {bubbles: true}));
    });
});
document.addEventListener("click", e => {
    if (e.target?.closest("[data-modal-close]")) closeModal();
    if (modal && !modal.hidden && e.target === modal) closeModal();
});
document.addEventListener("click", async e => {
    const link = e.target.closest("a[data-confirm]");
    if (!link) return;
    e.preventDefault();
    e.stopPropagation();
    if (await openConfirm(link.dataset.confirm || "确定操作？")) {
        window.location.href = link.href;
    }
});
document.addEventListener("click", e => {
    const quote = e.target.closest(".quote-reply");
    if (!quote) return;
    e.preventDefault();
    const textarea = document.querySelector("#reply textarea[name=body]");
    const panel = document.getElementById("reply");
    if (!textarea || !panel) {
        window.location.href = quote.href;
        return;
    }
    const floor = (quote.dataset.floor || "").trim();
    const marker = /^\d+$/.test(floor) && Number(floor) > 0 ? " #" + floor : "";
    const mention = "@" + (quote.dataset.username || "").trim() + marker + " ";
    if (!textarea.value.includes(mention)) {
        const prefix = textarea.value && !textarea.value.endsWith("\n") ? "\n" : "";
        textarea.value += prefix + mention;
    }
    panel.scrollIntoView({block:"center"});
    textarea.focus();
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
});
/* --- 人机验证：Cloudflare Turnstile。显式渲染并记下 widget id，提交后按 id 重置 --- */
/* token 是一次性的：同一页面第二次提交前必须 reset，否则后端拿到的是已消费的 token。 */
const turnstileFields = () => Array.from(document.querySelectorAll("[data-turnstile]"));
const renderTurnstile = () => {
    if (!window.turnstile || typeof window.turnstile.render !== "function") return false;
    turnstileFields().forEach(field => {
        if (field.dataset.turnstileWidgetId) return;
        try {
            const widgetId = window.turnstile.render(field, {
                sitekey: field.dataset.sitekey,
                action: field.dataset.action || undefined,
                language: field.dataset.language || undefined,
            });
            if (widgetId) field.dataset.turnstileWidgetId = widgetId;
        } catch (_) {
            /* 单个组件渲染失败不能影响本页其它脚本 */
        }
    });
    return true;
};
const resetTurnstile = form => {
    if (!window.turnstile || typeof window.turnstile.reset !== "function") return;
    const field = (form || document).querySelector("[data-turnstile]");
    const widgetId = field?.dataset?.turnstileWidgetId;
    if (!widgetId) return;
    try {
        window.turnstile.reset(widgetId);
    } catch (_) {}
};
const initTurnstile = () => {
    if (!turnstileFields().length) return;
    /* 不用 turnstile.ready()：api.js 带 async/defer 时它会直接抛错，而这段代码一旦抛出，
       后面的提交拦截就注册不上。api.js 的落地时机不定，所以轮询等 render 就绪，超时放弃
       （没有 token 的提交后端会拒）。 */
    renderTurnstile();
    let attempts = 0;
    const timer = setInterval(() => {
        if (renderTurnstile() || ++attempts > 100) clearInterval(timer);
    }, 150);
};
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initTurnstile);
else initTurnstile();
document.addEventListener("submit", async e => {
    if (e.defaultPrevented) return;
    const promptField = e.submitter?.dataset?.promptField || e.target?.dataset?.promptField || "";
    if (promptField) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        const input = e.target.elements?.[promptField];
        const value = await openPrompt(e.submitter?.dataset?.promptMessage || e.target?.dataset?.promptMessage || "请输入", e.submitter?.dataset?.promptTitle || e.target?.dataset?.promptTitle || "请输入", e.submitter?.dataset?.promptValue || e.target?.dataset?.promptValue || input?.value || "1");
        if (value === null || value === false) return;
        if (input) input.value = value;
        markButtonPending(e.submitter || e.target.querySelector("button[type=submit],button:not([type]),input[type=submit]"));
        e.target.submit();
        return;
    }
    const confirmMessage = e.submitter?.dataset?.confirm || e.target?.dataset?.confirm || "";
    if (confirmMessage) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        if (!await openConfirm(confirmMessage)) return;
        if (e.target?.dataset?.noAjax === "1") {
            markButtonPending(e.submitter || e.target.querySelector("button[type=submit],button:not([type]),input[type=submit]"));
            e.target.submit();
            return;
        }
    }
    const replyForm = e.target.closest(".ajax-reply-form");
    if (replyForm) {
        e.preventDefault();
        if (replyForm.dataset.submitting === "1") return;
        replyForm.dataset.submitting = "1";
        const button = e.submitter?.form === replyForm ? e.submitter : replyForm.querySelector("button[type=submit],button:not([type]),input[type=submit]");
        const status = replyForm.querySelector(".reply-status");
        const list = document.querySelector(".topic-post-list");
        const loadingText = button?.dataset?.loadingText || "";
        const buttonText = button?.textContent || "";
        if (button) {
            button.disabled = true;
            button.setAttribute("aria-busy", "true");
            if (loadingText) {
                button.textContent = loadingText;
            }
        }
        if (status) status.textContent = "提交中";
        try {
            const response = await fetch(formActionUrl(replyForm), {method: "POST", body: new FormData(replyForm), headers: {"X-Requested-With": "XMLHttpRequest"}});
            const data = await response.json();
            if (!data.ok) throw new Error(data.message || "提交失败");
            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }
            list?.querySelector(".empty-state")?.remove();
            if (data.html && list) {
                list.insertAdjacentHTML("beforeend", data.html);
            }
            const title = document.querySelector(".post-topic-title");
            const stats = title?.querySelector(".post-content-stats");
            if (title) {
                if (data.stats_html) {
                    if (stats) stats.outerHTML = data.stats_html;
                    else title.insertAdjacentHTML("beforeend", data.stats_html);
                } else if (stats) stats.remove();
            }
            replyForm.reset();
            if (status) status.textContent = "已回复";
            if (data.tip) showToast(data.tip);
        } catch (err) {
            const message = err?.message || "提交失败";
            if (status) status.textContent = message;
            showToast(message);
        } finally {
            delete replyForm.dataset.submitting;
            resetTurnstile(replyForm);
            if (button) {
                button.disabled = false;
                button.removeAttribute("aria-busy");
                if (loadingText) {
                    button.textContent = buttonText;
                }
            }
        }
        return;
    }
    const form = e.target.closest("form");
    if (!form) return;
    const modalForm = form.closest("#notify-modal");
    if (form.dataset.noAjax === "1") {
        markButtonPending(e.submitter || form.querySelector("button[type=submit],button:not([type]),input[type=submit]"));
        return;
    }
    if ((form.method || "").toLowerCase() !== "post") return;
    e.preventDefault();
    const button = e.submitter || form.querySelector("button[type=submit],button:not([type]),input[type=submit]");
    const loadingText = button?.dataset?.loadingText || "";
    const buttonText = button?.textContent || "";
    const resetButton = () => {
        if (!button) return;
        button.disabled = false;
        button.removeAttribute("aria-busy");
        if (loadingText) {
            button.textContent = buttonText;
        }
    };
    if (button) {
        button.disabled = true;
        button.setAttribute("aria-busy", "true");
        if (loadingText) {
            button.textContent = loadingText;
        }
    }
    try {
        const body = new FormData(form);
        if (button?.name) body.append(button.name, button.value ?? "1");
        const response = await fetch(formActionUrl(form), {method: "POST", body, headers: {"X-Requested-With": "XMLHttpRequest"}});
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (_) {
            throw new Error("操作失败");
        }
        if (!data.ok) throw new Error(data.message || "操作失败");
        const successMessage = data.tip && data.tip !== data.message
            ? `${data.message || "操作完成"}，${data.tip}`
            : (data.message || data.tip || "操作完成");
        if (data.modal && typeof data.modal === "object") {
            openModal(data.modal.title || data.message || "提示", data.modal.html || "");
            resetButton();
            return;
        }
        if (modalForm) closeModal();
        const replaceTarget = form.dataset.replaceTarget || "";
        const replaceEl = replaceTarget ? (form.closest(replaceTarget) || document.querySelector(replaceTarget)) : null;
        if (data.refresh && replaceEl) {
            try {
                const panelResponse = await fetch(window.location.href, {credentials: "same-origin"});
                const panelDoc = new DOMParser().parseFromString(await panelResponse.text(), "text/html");
                const panel = panelDoc.querySelector(replaceTarget);
                if (panel) replaceEl.outerHTML = panel.outerHTML;
            } catch (_) {}
            if (!replaceEl || replaceEl.isConnected) resetButton();
            resetTurnstile(form);
            showToast(successMessage);
            return;
        }
        showToast(successMessage);
        if (replaceEl && data.html) {
            replaceEl.outerHTML = data.html;
            return;
        }
        const removeTarget = form.dataset.removeTarget || "";
        const removeEl = removeTarget ? form.closest(removeTarget) : null;
        if (removeEl) {
            removeEl.remove();
            return;
        }
        if (data.redirect) setTimeout(() => { window.location.href = data.redirect; }, 800);
    } catch (err) {
        showToast(err?.message || "操作失败");
        resetTurnstile(form);
        resetButton();
    }
});
window.addEventListener("load", () => {
    const replyId = new URLSearchParams(window.location.search).get("replyid") || "";
    const floor = new URLSearchParams(window.location.search).get("floor") || "";
    const target = /^\d+$/.test(replyId) ? document.getElementById("post-" + replyId) : (/^\d+$/.test(floor) ? document.querySelector('[data-floor="' + floor + '"]') : null);
    if (target) target.scrollIntoView({block:"center"});
});
/* --- Markdown 编辑器：EasyMDE（app/assets/vendor/easymde，MIT）；预览走后端同一个渲染函数 --- */
const mdIcon = paths => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
const mdEditors = new WeakMap();
const mdPreviewCache = new WeakMap();
const mdPreviewTimers = new WeakMap();
const mdPreviewTokens = new WeakMap();
/* 工具栏沿用 EasyMDE 自带动作，只换成内联 SVG 图标，避免再依赖 Font Awesome CDN */
const mdToolbar = () => [
    {name: "undo", action: EasyMDE.undo, title: "撤销", icon: mdIcon('<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>')},
    {name: "redo", action: EasyMDE.redo, title: "重做", icon: mdIcon('<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>')},
    "|",
    {name: "bold", action: EasyMDE.toggleBold, title: "粗体 Ctrl+B", icon: mdIcon('<path d="M6 4h8a4 4 0 0 1 0 8H6zM6 12h9a4 4 0 0 1 0 8H6z"/>')},
    {name: "italic", action: EasyMDE.toggleItalic, title: "斜体 Ctrl+I", icon: mdIcon('<line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/><line x1="15" y1="4" x2="9" y2="20"/>')},
    {name: "strikethrough", action: EasyMDE.toggleStrikethrough, title: "删除线", icon: mdIcon('<path d="M16 4H9a3 3 0 0 0-2.83 4"/><path d="M14 12a4 4 0 0 1 0 8H6"/><line x1="4" y1="12" x2="20" y2="12"/>')},
    {name: "heading", action: EasyMDE.toggleHeadingSmaller, title: "标题", icon: mdIcon('<path d="M6 4v16M18 4v16M6 12h12"/>')},
    {name: "code", action: EasyMDE.toggleCodeBlock, title: "代码块", icon: mdIcon('<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>')},
    {name: "quote", action: EasyMDE.toggleBlockquote, title: "引用", icon: mdIcon('<path fill="currentColor" stroke="none" d="M16 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"/><path fill="currentColor" stroke="none" d="M5 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"/>')},
    {name: "unordered-list", action: EasyMDE.toggleUnorderedList, title: "无序列表", icon: mdIcon('<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>')},
    {name: "ordered-list", action: EasyMDE.toggleOrderedList, title: "有序列表", icon: mdIcon('<line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-1 2-2s-1-1-2-1"/>')},
    "|",
    {name: "link", action: EasyMDE.drawLink, title: "链接 Ctrl+K", icon: mdIcon('<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>')},
    {name: "image", action: EasyMDE.drawImage, title: "图片", icon: mdIcon('<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>')},
    {name: "table", action: EasyMDE.drawTable, title: "表格", icon: mdIcon('<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/>')},
    {name: "horizontal-rule", action: EasyMDE.drawHorizontalRule, title: "分隔线", icon: mdIcon('<line x1="4" y1="12" x2="20" y2="12"/>')},
    "|",
    {name: "preview", action: EasyMDE.togglePreview, title: "预览", icon: mdIcon('<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>')},
    {name: "side-by-side", action: EasyMDE.toggleSideBySide, title: "并排预览", icon: mdIcon('<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="12" y1="3" x2="12" y2="21"/>')},
    {name: "fullscreen", action: EasyMDE.toggleFullScreen, title: "全屏", icon: mdIcon('<path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>')},
];
const mdPreviewRequest = async (textarea, preview) => {
    const url = textarea.dataset.previewUrl || "";
    const form = textarea.closest("form");
    if (!url || !preview || !form) return;
    const token = (mdPreviewTokens.get(textarea) || 0) + 1;
    mdPreviewTokens.set(textarea, token);
    const body = new FormData();
    body.append("_csrf", form.querySelector('input[name="_csrf"]')?.value || "");
    body.append("body", textarea.value);
    const topicId = form.querySelector('input[name="topic_id"]')?.value || form.querySelector('input[name="id"]')?.value || "";
    if (topicId) body.append("topic_id", topicId);
    try {
        const response = await fetch(url, {method: "POST", body, headers: {"X-Requested-With": "XMLHttpRequest"}, credentials: "same-origin"});
        const data = await response.json().catch(() => null);
        if (mdPreviewTokens.get(textarea) !== token) return;
        if (!data || !data.ok) throw new Error(data?.message || "预览失败");
        mdPreviewCache.set(textarea, data.html || "");
        preview.innerHTML = data.html || '<p class="editor-preview-empty">还没有内容</p>';
    } catch (error) {
        if (mdPreviewTokens.get(textarea) !== token || mdPreviewCache.has(textarea)) return;
        preview.innerHTML = '<p class="editor-preview-empty">预览失败，请稍后重试</p>';
    }
};
const mdPreviewSchedule = (textarea, preview) => {
    clearTimeout(mdPreviewTimers.get(textarea));
    mdPreviewTimers.set(textarea, setTimeout(() => mdPreviewRequest(textarea, preview), 250));
};
const mdInitEditors = () => {
    if (typeof window.EasyMDE !== "function") return;
    document.querySelectorAll("textarea[data-markdown-editor]").forEach(textarea => {
        if (textarea.dataset.markdownReady === "1") return;
        textarea.dataset.markdownReady = "1";
        // CodeMirror 会隐藏原文本域，隐藏的必填控件会让浏览器拒绝提交，改成提交前用脚本校验
        textarea.removeAttribute("required");
        const editor = new EasyMDE({
            element: textarea,
            autoDownloadFontAwesome: false,
            spellChecker: false,
            status: false,
            forceSync: true,
            minHeight: "132px",
            placeholder: textarea.getAttribute("placeholder") || "",
            toolbar: mdToolbar(),
            // 预览面板直接套用正文排版样式，预览和帖子看起来一致
            previewClass: ["editor-preview", "post-content"],
            previewRender: (plainText, preview) => {
                const pane = preview instanceof HTMLElement ? preview : null;
                if (pane) mdPreviewSchedule(textarea, pane);
                return mdPreviewCache.get(textarea) ?? '<p class="editor-preview-empty">正在渲染…</p>';
            },
        });
        mdEditors.set(textarea, editor);
    });
};
document.addEventListener("submit", event => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    if (!form) return;
    const empty = Array.from(form.querySelectorAll("textarea[data-markdown-editor][data-markdown-required]")).find(textarea => textarea.value.trim() === "");
    if (!empty) return;
    event.preventDefault();
    event.stopPropagation();
    showToast("内容不能为空");
    mdEditors.get(empty)?.codemirror.focus();
}, true);
document.addEventListener("reset", event => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    if (!form) return;
    form.querySelectorAll("textarea[data-markdown-editor]").forEach(textarea => mdEditors.get(textarea)?.value(""));
});
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", mdInitEditors);
else mdInitEditors();
/* --- 移动端体验：返回顶部 + 列表无限滚动（窄屏 ≤720px 生效，PC 保留手动分页） --- */
const backToTop = document.getElementById("back-to-top");
if (backToTop) {
    const toggleBackToTop = () => { backToTop.hidden = window.scrollY < 240; };
    window.addEventListener("scroll", toggleBackToTop, {passive: true});
    backToTop.addEventListener("click", () => window.scrollTo({top: 0, behavior: "smooth"}));
    toggleBackToTop();
}
const initInfiniteScroll = () => {
    const list = document.querySelector(".main-panel .post-list");
    let bar = document.querySelector(".pagination-bar");
    // 没有分页条或没有下一页链接（如已到末页）时自然不启用
    if (!bar || !list || !window.matchMedia("(max-width: 720px)").matches || !("IntersectionObserver" in window)) return;
    const nextHref = () => {
        // 下一页链接带 pagination-next 类：中文/英文文案都能定位，搜索页翻页也是普通链接
        const link = bar.querySelector("a.pagination-next");
        return link ? link.getAttribute("href") : null;
    };
    if (!nextHref()) return;
    const status = document.createElement("div");
    status.className = "infinite-status";
    status.textContent = "上拉加载更多";
    bar.after(status);
    let loading = false;
    const markEnd = () => { status.textContent = "已经到底了"; status.removeAttribute("data-retry"); observer.disconnect(); };
    const loadNext = async () => {
        const href = nextHref();
        if (loading) return;
        if (!href) { markEnd(); return; }
        loading = true;
        status.textContent = "正在加载…";
        status.removeAttribute("data-retry");
        try {
            const response = await fetch(href, {credentials: "same-origin"});
            if (!response.ok) throw new Error();
            const doc = new DOMParser().parseFromString(await response.text(), "text/html");
            const freshBar = doc.querySelector(".pagination-bar");
            // 空态行不追加：下一页如果已经没有数据，直接按到底处理
            const incoming = Array.from(doc.querySelectorAll(".main-panel .post-list > li")).filter(li => !li.classList.contains("empty-state"));
            if (!incoming.length || !freshBar) throw new Error();
            list.append(...incoming);
            bar.replaceWith(freshBar);
            bar = freshBar;
            bar.after(status);
            // 地址栏同步到已加载的页码，刷新后仍停留在当前位置
            history.replaceState(null, "", href);
            if (!nextHref()) {
                markEnd();
            } else {
                status.textContent = "上拉加载更多";
                // 追加后状态条可能仍在触发区（内容不足一屏），手动补一次加载
                if (status.getBoundingClientRect().top < window.innerHeight + 480) {
                    loading = false;
                    loadNext();
                    return;
                }
            }
        } catch (_) {
            status.textContent = "加载失败，点击重试";
            status.dataset.retry = "1";
        }
        loading = false;
    };
    status.addEventListener("click", () => { if (status.dataset.retry === "1") loadNext(); });
    const observer = new IntersectionObserver(entries => {
        if (entries.some(entry => entry.isIntersecting)) loadNext();
    }, {rootMargin: "480px 0px"});
    observer.observe(status);
};
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initInfiniteScroll);
else initInfiniteScroll();

// 被打码的联系方式(******)改为可点击:弹窗引导微信扫码或长按识别添加好友
const bodyData = document.body instanceof HTMLElement ? document.body.dataset : {};
const contactQrUrl = bodyData.contactQr || "";
// 弹窗文案由服务端按当前语言渲染进 body data 属性，前端不再硬编码中文
const openContactQrModal = () => {
    openModal(bodyData.contactQrTitle || "添加微信", '<img class="contact-qr" src="' + contactQrUrl + '" alt="' + (bodyData.contactQrAlt || "微信二维码") + '">'
        + '<p class="contact-qr-hint">' + (bodyData.contactQrHint || "联系方式已隐藏。打开微信「扫一扫」扫描二维码，或在手机上长按识别二维码添加好友。") + '</p>');
};
const wrapMaskedContacts = root => {
    if (!contactQrUrl || !root) return;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
        // 已经包装过的占位按钮里的文本不再二次包装，避免 MutationObserver 与包装互相触发
        acceptNode: node => node.parentElement && node.parentElement.closest(".contact-masked")
            ? NodeFilter.FILTER_REJECT
            : (node.nodeValue.includes("******") ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP)
    });
    const targets = [];
    for (let node = walker.nextNode(); node; node = walker.nextNode()) targets.push(node);
    targets.forEach(node => {
        const frag = document.createDocumentFragment();
        node.nodeValue.split("******").forEach((part, index, parts) => {
            if (part !== "") frag.append(part);
            if (index < parts.length - 1) {
                const button = document.createElement("button");
                button.type = "button";
                button.className = "contact-masked";
                button.title = bodyData.contactQrAria || "联系方式已隐藏，点击查看添加方式";
                button.setAttribute("aria-label", bodyData.contactQrAria || "联系方式已隐藏，点击查看添加方式");
                button.innerHTML = '******<span class="contact-masked-go">' + (bodyData.contactQrMore || "点击查看") + '</span>';
                frag.append(button);
            }
        });
        node.replaceWith(frag);
    });
};
const langBanner = document.getElementById("lang-banner");
if (langBanner) {
    langBanner.addEventListener("click", e => {
        if (!e.target.closest("[data-lang-banner-close]")) return;
        const htmlLang = (document.documentElement.lang || "zh").slice(0, 2).toLowerCase();
        document.cookie = "bbs_lang=" + htmlLang + "; max-age=31536000; path=/; samesite=lax";
        langBanner.remove();
    });
}
if (contactQrUrl) {
    wrapMaskedContacts(document.getElementById("main"));
    // 无限滚动等动态插入的列表行同样要包装
    const contactMain = document.getElementById("main");
    if (contactMain) {
        const contactObserver = new MutationObserver(mutations => {
            mutations.forEach(mutation => mutation.addedNodes.forEach(node => {
                if (node.nodeType === Node.ELEMENT_NODE) wrapMaskedContacts(node);
            }));
        });
        contactObserver.observe(contactMain, {childList: true, subtree: true});
    }
    document.addEventListener("click", e => {
        if (e.target.closest(".contact-masked")) openContactQrModal();
    });
}

/* 首页焦点轮播：自动轮换 + 圆点跳转，悬停/聚焦/触屏暂停，尊重系统减少动效偏好 */
const heroCarousel = document.getElementById("hero-carousel");
if (heroCarousel) {
    const track = heroCarousel.querySelector(".hero-track");
    const slides = Array.from(track.children);
    const dots = Array.from(heroCarousel.querySelectorAll(".hero-dot"));
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    let heroIndex = 0, heroTimer = null;
    const heroGoTo = i => {
        heroIndex = (i + slides.length) % slides.length;
        track.scrollTo({left: heroIndex * track.clientWidth, behavior: reduced ? "auto" : "smooth"});
        dots.forEach((d, di) => d.classList.toggle("active", di === heroIndex));
    };
    const heroStop = () => { if (heroTimer) { clearInterval(heroTimer); heroTimer = null; } };
    const heroStart = () => { if (!reduced && !heroTimer && document.visibilityState === "visible") heroTimer = setInterval(() => heroGoTo(heroIndex + 1), 5000); };
    const heroRestart = () => { heroStop(); heroStart(); };
    dots.forEach(d => d.addEventListener("click", () => { heroGoTo(+d.dataset.slide); heroRestart(); }));
    // 手动滑动时同步圆点（scroll 事件里按滚动位置反推当前屏）
    track.addEventListener("scroll", () => {
        if (track.clientWidth === 0) return;
        const i = Math.round(track.scrollLeft / track.clientWidth);
        if (i !== heroIndex && i >= 0 && i < slides.length) {
            heroIndex = i;
            dots.forEach((d, di) => d.classList.toggle("active", di === i));
        }
    }, {passive: true});
    heroCarousel.addEventListener("pointerenter", heroStop);
    heroCarousel.addEventListener("pointerleave", heroStart);
    heroCarousel.addEventListener("focusin", heroStop);
    heroCarousel.addEventListener("focusout", heroStart);
    track.addEventListener("touchstart", heroStop, {passive: true});
    document.addEventListener("visibilitychange", () => document.visibilityState === "visible" ? heroStart() : heroStop());
    // 图片懒加载导致的宽度变化不影响布局（flex 定宽），但窗口缩放后要吸附回当前屏
    window.addEventListener("resize", () => heroGoTo(heroIndex));
    heroStart();
}
