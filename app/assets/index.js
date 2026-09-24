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
const closeModal = () => {
    if (confirmResolve) {
        const resolve = confirmResolve;
        confirmResolve = null;
        resolve(false);
    }
    if (modal) modal.hidden = true;
    if (modalBody) modalBody.innerHTML = "";
};
const openModal = (title, html) => {
    if (!modal || !modalBody) return;
    if (modalTitle) modalTitle.textContent = title;
    modalBody.innerHTML = html;
    modal.hidden = false;
};
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
        const response = await fetch(mobileMenu.dataset.mobileMenuUrl, {headers: {"X-Requested-With": "XMLHttpRequest"}});
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
    if (e.key === "Escape") closeMobileMenu();
});
const finishConfirm = (ok) => {
    const resolve = confirmResolve;
    confirmResolve = null;
    if (modal) modal.hidden = true;
    if (modalBody) modalBody.innerHTML = "";
    if (resolve) resolve(ok);
};
const _openModalBox = (title, fallback, builderFn) => new Promise(resolve => {
    if (!modal || !modalBody) { resolve(fallback); return; }
    if (confirmResolve) {
        const previous = confirmResolve;
        confirmResolve = null;
        previous(false);
    }
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
    ok.className = "danger";
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
        resetButton();
    }
});
window.addEventListener("load", () => {
    const replyId = new URLSearchParams(window.location.search).get("replyid") || "";
    const floor = new URLSearchParams(window.location.search).get("floor") || "";
    const target = /^\d+$/.test(replyId) ? document.getElementById("post-" + replyId) : (/^\d+$/.test(floor) ? document.querySelector('[data-floor="' + floor + '"]') : null);
    if (target) target.scrollIntoView({block:"center"});
});
