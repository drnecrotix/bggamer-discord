(function () {
  const body = document.body;
  const navBars = Array.from(document.querySelectorAll(".navbar[data-site-nav]"));

  if (!body || !navBars.length) {
    return;
  }

  const DEFAULT_RULES_URL = "https://discord.com/channels/114667416247599110/1035251098031755294";
  const DEFAULT_INVITE_URL = "https://discord.gg/PFkjeKBuxH";
  const uiLang = normalizeLang(body.dataset.uiLang || "en");
  const labels = getLabels(uiLang);
  const siteRoot = resolveSiteRootUrl(body);
  const pageKey = (body.dataset.navPage || detectPageKey()).toLowerCase();
  const rulesUrl = (body.dataset.rulesUrl || DEFAULT_RULES_URL).trim();
  const inviteUrl = (body.dataset.inviteUrl || DEFAULT_INVITE_URL).trim();
  const ctaMode = (body.dataset.navCta || defaultCtaMode(pageKey)).toLowerCase();
  const ctaHref = (body.dataset.navCtaHref || "").trim();
  const ctaLabel = (body.dataset.navCtaLabel || "").trim();
  const activeKey = resolveActiveKey(pageKey, window.location.hash);
  const navItems = buildNavItems({
    pageKey,
    rulesUrl,
    siteRoot,
  });

  renderNavigation();
  window.__bgSiteNav = true;

  const collapses = document.querySelectorAll(".navbar-collapse");

  if (!collapses.length || !window.bootstrap || !window.bootstrap.Collapse) {
    return;
  }

  const mobileQuery = window.matchMedia("(max-width: 991.98px)");

  collapses.forEach((collapse) => {
    const topbar = collapse.closest(".topbar");
    const toggle =
      collapse.id
        ? document.querySelector(`[data-bs-target="#${collapse.id}"]`)
        : null;

    collapse.addEventListener("show.bs.collapse", () => {
      document.body.classList.add("mobile-nav-open");

      if (topbar) {
        topbar.classList.add("is-nav-open");
      }
    });

    collapse.addEventListener("shown.bs.collapse", () => {
      applyMobilePanelStyles(collapse, mobileQuery);
    });

    collapse.addEventListener("hidden.bs.collapse", () => {
      resetMobilePanelStyles(collapse);

      if (!document.querySelector(".navbar-collapse.show")) {
        document.body.classList.remove("mobile-nav-open");
      }

      if (topbar) {
        topbar.classList.remove("is-nav-open");
      }
    });

    bindCollapseLinks(collapse, mobileQuery);

    if (toggle) {
      toggle.addEventListener("click", () => {
        window.setTimeout(() => {
          if (collapse.classList.contains("show")) {
            document.body.classList.add("mobile-nav-open");

            if (topbar) {
              topbar.classList.add("is-nav-open");
            }

            applyMobilePanelStyles(collapse, mobileQuery);
            bindCollapseLinks(collapse, mobileQuery);
            return;
          }

          resetMobilePanelStyles(collapse);

          if (topbar) {
            topbar.classList.remove("is-nav-open");
          }

          document.body.classList.remove("mobile-nav-open");
        }, 360);
      });
    }
  });

  window.addEventListener("resize", () => {
    if (mobileQuery.matches) {
      return;
    }

    document.body.classList.remove("mobile-nav-open");
    document.querySelectorAll(".topbar.is-nav-open").forEach((topbar) => {
      topbar.classList.remove("is-nav-open");
    });

    collapses.forEach((collapse) => {
      resetMobilePanelStyles(collapse);
    });
  });

  function renderNavigation() {
    navBars.forEach((navBar) => {
      const list = navBar.querySelector("[data-site-nav-list]") || navBar.querySelector(".navbar-nav--site");
      const collapse = navBar.querySelector(".navbar-collapse");

      if (list) {
        list.innerHTML = navItems
          .map((item) => renderNavItem(item, item.key === activeKey))
          .join("");
      }

      if (!collapse) {
        return;
      }

      let actionSlot = navBar.querySelector("[data-site-nav-actions]");

      if (!actionSlot) {
        actionSlot = document.createElement("div");
        actionSlot.className = "site-nav__actions";
        actionSlot.setAttribute("data-site-nav-actions", "");
        collapse.append(actionSlot);
      }

      actionSlot.classList.add("site-nav__actions");
      actionSlot.innerHTML = renderActionSlot();
    });
  }

  function renderActionSlot() {
    if (ctaMode === "invite") {
      return `<a class="btn btn-brand navbar-cta ms-lg-4" href="${escapeHtml(inviteUrl)}">${escapeHtml(labels.openInvite)}</a>`;
    }

    if (ctaMode === "logout" && ctaHref !== "") {
      const label = ctaLabel !== "" ? ctaLabel : labels.logout;
      return `<a class="btn btn-ghost navbar-cta ms-lg-4" href="${escapeHtml(ctaHref)}">${escapeHtml(label)}</a>`;
    }

    return "";
  }

  function renderNavItem(item, isActive) {
    const currentAttr = isActive ? ' aria-current="page"' : "";
    const externalAttrs = item.external ? ' target="_blank" rel="noreferrer"' : "";

    return `<li class="nav-item"><a class="nav-link" href="${escapeHtml(item.href)}"${currentAttr}${externalAttrs}>${escapeHtml(item.label)}</a></li>`;
  }

  function buildNavItems({ pageKey: currentPage, rulesUrl: currentRulesUrl, siteRoot: currentSiteRoot }) {
    const lobbyHref = currentPage === "lobby" ? "#top" : withLang(currentSiteRoot);
    const activityHref = currentPage === "lobby" ? "#activity" : withLang(`${currentSiteRoot}#activity`);
    const botsHref = currentPage === "lobby" ? "#bots" : withLang(`${currentSiteRoot}#bots`);

    return [
      { key: "lobby", label: labels.lobby, href: lobbyHref },
      { key: "activity", label: labels.activity, href: activityHref },
      { key: "bots", label: labels.bots, href: botsHref },
      { key: "bans", label: labels.bans, href: withLang(new URL("bans/", currentSiteRoot).href) },
      { key: "appeal", label: labels.appeal, href: withLang(new URL("appeal/", currentSiteRoot).href) },
      { key: "rules", label: labels.rules, href: currentRulesUrl, external: /^https?:\/\//i.test(currentRulesUrl) },
      { key: "mod", label: labels.mod, href: withLang(new URL("mod/", currentSiteRoot).href) },
    ];
  }

  function withLang(url) {
    if (uiLang !== "bg" && uiLang !== "en") {
      return url;
    }

    if (url.startsWith("#")) {
      return url;
    }

    const target = new URL(url, window.location.href);
    target.searchParams.set("lang", uiLang);
    return target.href;
  }

  function resolveActiveKey(currentPage, currentHash) {
    if (currentPage !== "lobby") {
      return currentPage === "appeal-success" ? "appeal" : currentPage;
    }

    if (currentHash === "#activity") {
      return "activity";
    }

    if (currentHash === "#bots") {
      return "bots";
    }

    return "lobby";
  }

  function defaultCtaMode(currentPage) {
    return currentPage === "mod" ? "none" : "invite";
  }

  function detectPageKey() {
    const path = window.location.pathname.toLowerCase();

    if (path.includes("/mod")) {
      return "mod";
    }

    if (path.includes("/appeal")) {
      return "appeal";
    }

    if (path.includes("/bans")) {
      return "bans";
    }

    return "lobby";
  }

  function resolveSiteRootUrl(currentBody) {
    const baseValue = (currentBody.dataset.siteBase || "").trim();

    if (baseValue !== "") {
      return ensureTrailingSlash(new URL(baseValue, window.location.href).href);
    }

    const script = Array.from(document.scripts)
      .reverse()
      .find((entry) => /assets\/site-nav\.js(?:\?|$)/i.test(entry.src || entry.getAttribute("src") || ""));

    if (script && script.src) {
      return ensureTrailingSlash(new URL("../", script.src).href);
    }

    return ensureTrailingSlash(new URL("./", window.location.href).href);
  }

  function ensureTrailingSlash(url) {
    return /\/$/.test(url) ? url : `${url}/`;
  }

  function normalizeLang(value) {
    const normalized = String(value || "").trim().toLowerCase();
    return normalized === "bg" ? "bg" : "en";
  }

  function getLabels(lang) {
    if (lang === "bg") {
      return {
        lobby: "Лоби",
        activity: "Активност",
        bots: "Ботове",
        bans: "Банове",
        appeal: "Обжалване",
        rules: "Правила",
        mod: "Мод панел",
        openInvite: "Отвори поканата",
        logout: "Изход",
      };
    }

    return {
      lobby: "Lobby",
      activity: "Activity",
      bots: "Bots",
      bans: "Bans",
      appeal: "Appeal",
      rules: "Rules",
      mod: "Mod Panel",
      openInvite: "Open Invite",
      logout: "Logout",
    };
  }

  function bindCollapseLinks(collapse, mobileQuery) {
    collapse.querySelectorAll(".nav-link, .btn").forEach((link) => {
      if (link.dataset.navBound === "1") {
        return;
      }

      link.dataset.navBound = "1";
      link.addEventListener("click", () => {
        if (!mobileQuery.matches || !collapse.classList.contains("show")) {
          return;
        }

        const instance = window.bootstrap.Collapse.getOrCreateInstance(collapse, {
          toggle: false,
        });

        instance.hide();
      });
    });
  }

  function applyMobilePanelStyles(collapse, mobileQuery) {
    if (!mobileQuery.matches) {
      return;
    }

    Object.assign(collapse.style, {
      position: "absolute",
      top: "calc(100% - 0.3rem)",
      left: "0",
      right: "0",
      zIndex: "1001",
      display: "grid",
      gap: "1rem",
      marginTop: "0",
      padding: "1rem",
      border: "1px solid rgba(255, 255, 255, 0.1)",
      borderRadius: "24px",
      background: "linear-gradient(180deg, rgba(7, 12, 24, 0.98), rgba(16, 12, 28, 0.96))",
      boxShadow: "0 24px 64px rgba(0, 0, 0, 0.42), inset 0 1px 0 rgba(255, 255, 255, 0.03)",
    });

    collapse.querySelectorAll(".nav-link").forEach((link) => {
      Object.assign(link.style, {
        width: "100%",
        justifyContent: "flex-start",
        padding: "0.95rem 1rem",
        fontSize: "1.08rem",
        background: "rgba(255, 255, 255, 0.05)",
        border: "1px solid rgba(255, 255, 255, 0.08)",
        borderRadius: "18px",
      });
    });

    collapse.querySelectorAll(".btn").forEach((button) => {
      Object.assign(button.style, {
        width: "100%",
        marginLeft: "0",
      });
    });
  }

  function resetMobilePanelStyles(collapse) {
    collapse.removeAttribute("style");

    collapse.querySelectorAll(".nav-link, .btn").forEach((element) => {
      element.removeAttribute("style");
    });
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }
})();
