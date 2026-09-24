(function () {
  const app = document.getElementById("banCenterApp");

  if (!app) {
    return;
  }

  const prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
  const endpoint = app.dataset.endpoint || "";
  const defaultState = {
    page: parsePositiveInt(app.dataset.initialPage, 1),
    totalPages: 1,
    perPage: getPerPage(),
    filteredTotal: 0,
    activeTotal: 0,
    query: app.dataset.initialQuery || "",
    status: app.dataset.initialStatus || "active",
    dateFrom: app.dataset.initialDateFrom || "",
    dateTo: app.dataset.initialDateTo || "",
    direction: "next",
    requestId: 0,
    openReference: null
  };

  const state = { ...defaultState };
  const elements = {
    form: document.getElementById("banFilters"),
    search: document.getElementById("banSearch"),
    status: document.getElementById("banStatus"),
    dateFrom: document.getElementById("banDateFrom"),
    dateTo: document.getElementById("banDateTo"),
    reset: document.getElementById("banResetFilters"),
    grid: document.getElementById("banCards"),
    state: document.getElementById("banState"),
    summary: document.getElementById("banResultsSummary"),
    prev: document.getElementById("banPrevPage"),
    next: document.getElementById("banNextPage"),
    pageButtons: document.getElementById("banPageButtons"),
    pageStatus: document.getElementById("banPageStatus"),
    heroActive: document.getElementById("heroActiveCount"),
    panelActive: document.getElementById("panelActiveCount"),
    syncTime: document.getElementById("heroSyncTime"),
    apiState: document.getElementById("banApiState"),
    viewport: document.querySelector(".ban-board__viewport")
  };

  let touchStart = null;
  let resizeTimer = 0;
  let statusPicker = null;
  const revealObserver = createRevealObserver();

  statusPicker = setupStatusPicker();
  bindEvents();
  observeReveals(document);
  hydrateFromUrl();
  syncFormFromState();
  renderSkeletons(state.perPage);
  loadBans({ historyMode: "replace" });

  function bindEvents() {
    elements.form.addEventListener("submit", handleFilterSubmit);
    elements.reset.addEventListener("click", handleReset);
    elements.prev.addEventListener("click", () => navigateTo(state.page - 1, "prev"));
    elements.next.addEventListener("click", () => navigateTo(state.page + 1, "next"));
    elements.status.addEventListener("change", syncStatusPicker);

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        closeStatusPicker();
      }

      if (isTypingTarget(event.target)) {
        return;
      }

      if (event.key === "ArrowLeft") {
        navigateTo(state.page - 1, "prev");
      }

      if (event.key === "ArrowRight") {
        navigateTo(state.page + 1, "next");
      }
    });

    window.addEventListener("popstate", () => {
      hydrateFromUrl();
      syncFormFromState();
      loadBans({ historyMode: "skip" });
    });

    window.addEventListener("resize", () => {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(() => {
        const nextPerPage = getPerPage();

        if (nextPerPage !== state.perPage) {
          state.perPage = nextPerPage;
          state.openReference = null;
          loadBans({ historyMode: "replace" });
        }
      }, 180);
    });

    if (elements.viewport) {
      elements.viewport.addEventListener("touchstart", (event) => {
        const touch = event.changedTouches[0];
        touchStart = { x: touch.clientX, y: touch.clientY };
      }, { passive: true });

      elements.viewport.addEventListener("touchend", (event) => {
        if (!touchStart) {
          return;
        }

        const touch = event.changedTouches[0];
        const deltaX = touch.clientX - touchStart.x;
        const deltaY = touch.clientY - touchStart.y;
        touchStart = null;

        if (Math.abs(deltaX) < 60 || Math.abs(deltaX) <= Math.abs(deltaY) * 1.25) {
          return;
        }

        if (deltaX < 0) {
          navigateTo(state.page + 1, "next");
        } else {
          navigateTo(state.page - 1, "prev");
        }
      }, { passive: true });
    }
  }

  function handleFilterSubmit(event) {
    event.preventDefault();
    closeStatusPicker();
    state.query = elements.search.value.trim();
    state.status = elements.status.value;
    state.dateFrom = elements.dateFrom.value;
    state.dateTo = elements.dateTo.value;
    state.page = 1;
    state.direction = "next";
    state.openReference = null;
    loadBans({ historyMode: "push" });
  }

  function handleReset() {
    elements.form.reset();
    state.query = "";
    state.status = "active";
    state.dateFrom = "";
    state.dateTo = "";
    state.page = 1;
    state.direction = "prev";
    state.openReference = null;
    syncFormFromState();
    closeStatusPicker();
    loadBans({ historyMode: "push" });
  }

  async function loadBans(options) {
    const historyMode = options && options.historyMode ? options.historyMode : "replace";
    const requestId = ++state.requestId;
    const fetchUrl = new URL(endpoint, window.location.href);

    state.perPage = getPerPage();

    fetchUrl.searchParams.set("page", String(state.page));
    fetchUrl.searchParams.set("per_page", String(state.perPage));

    if (state.query) {
      fetchUrl.searchParams.set("q", state.query);
    }

    fetchUrl.searchParams.set("status", state.status && state.status !== "active" ? state.status : "active");

    if (state.dateFrom) {
      fetchUrl.searchParams.set("date_from", state.dateFrom);
    }

    if (state.dateTo) {
      fetchUrl.searchParams.set("date_to", state.dateTo);
    }

    setApiState("loading", "Loading");
    elements.grid.setAttribute("aria-busy", "true");

    if (!elements.grid.childElementCount) {
      renderSkeletons(state.perPage);
    } else {
      elements.grid.classList.add("is-loading");
    }

    try {
      const response = await fetch(fetchUrl.toString(), {
        headers: {
          Accept: "application/json"
        }
      });
      const payload = await response.json();

      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || "request_failed");
      }

      if (requestId !== state.requestId) {
        return;
      }

      await animateListOut();

      state.page = payload.summary.page;
      state.totalPages = payload.summary.total_pages;
      state.filteredTotal = payload.summary.filtered_total;
      state.activeTotal = payload.summary.active_total;

      const items = Array.isArray(payload.items) ? payload.items : [];

      if (state.openReference && !items.some((item) => item.public_reference === state.openReference)) {
        state.openReference = null;
      }

      updateHero(payload.summary.last_synced_at);
      renderRows(items);
      updateSummary(payload);
      updatePagination();
      updateHistory(historyMode);
      setApiState("live", "Live");
    } catch (error) {
      if (requestId !== state.requestId) {
        return;
      }

      await animateListOut();
      elements.grid.replaceChildren();
      showState({
        title: "Временен проблем при зареждането",
        message: "Публичният ban feed или базата данни не отговарят в момента. Опитайте отново.",
        actionLabel: "Опитай пак",
        actionHandler: () => loadBans({ historyMode: "replace" })
      });
      updateSummary(null);
      updatePagination(true);
      setApiState("error", "Error");
    } finally {
      if (requestId === state.requestId) {
        elements.grid.classList.remove("is-loading");
        elements.grid.setAttribute("aria-busy", "false");
      }
    }
  }

  function renderRows(items) {
    hideState();

    if (!items.length) {
      elements.grid.replaceChildren();
      const hasFilters = Boolean(state.query || state.dateFrom || state.dateTo || state.status !== "active");

      showState({
        title: hasFilters ? "Няма съвпадения" : "Няма активни ban записи",
        message: hasFilters
          ? "Няма записи за текущата комбинация от филтри. Коригирайте търсенето или статуса."
          : "Списъкът е празен. След следващия успешен sync тук ще се покажат публичните записи.",
        actionLabel: hasFilters ? "Изчисти филтрите" : "",
        actionHandler: hasFilters ? handleReset : null
      });
      return;
    }

    const fragment = document.createDocumentFragment();
    items.forEach((item) => {
      fragment.appendChild(createBanRow(item));
    });

    elements.grid.replaceChildren(fragment);
    observeReveals(elements.grid);
    animateListIn();
  }

  function createBanRow(item) {
    const article = createElement("article", "ban-list__item reveal");
    const isOpen = item.public_reference === state.openReference;
    const detailsId = `ban-details-${String(item.public_reference || "").replace(/[^a-z0-9_-]+/gi, "-")}`;

    article.setAttribute("data-reveal", "");
    article.dataset.reference = item.public_reference || "";

    if (isOpen) {
      article.classList.add("is-open");
    }

    const toggle = createElement("button", "ban-list__toggle");
    toggle.type = "button";
    toggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
    toggle.setAttribute("aria-controls", detailsId);

    const row = createElement("div", "ban-list__row");
    row.appendChild(createIdentityBlock(item));
    row.appendChild(createSummaryBlock(item));
    row.appendChild(createCaret());
    toggle.appendChild(row);

    const details = createElement("div", "ban-list__details");
    details.id = detailsId;
    details.hidden = !isOpen;
    details.appendChild(createDetailStack(item));
    details.appendChild(createDetailSidebar(item));

    toggle.addEventListener("click", () => {
      const shouldOpen = !article.classList.contains("is-open");
      closeOpenRows(article);
      setExpandedRow(article, toggle, details, shouldOpen);
      state.openReference = shouldOpen ? item.public_reference : null;
    });

    article.appendChild(toggle);
    article.appendChild(details);
    return article;
  }

  function createIdentityBlock(item) {
    const identity = createElement("div", "ban-list__identity");
    const avatarWrapper = document.createElement("div");
    const fallback = createElement("span", "ban-list__avatar-fallback", item.avatar_initials || "BG");
    fallback.hidden = Boolean(item.avatar_url);

    if (item.avatar_url) {
      const avatar = createElement("img", "ban-list__avatar");
      avatar.src = item.avatar_url;
      avatar.alt = `${item.username} avatar`;
      avatar.addEventListener("error", () => {
        fallback.hidden = false;
        avatar.remove();
      }, { once: true });
      avatarWrapper.appendChild(avatar);
    }

    avatarWrapper.appendChild(fallback);
    identity.appendChild(avatarWrapper);

    const main = createElement("div", "ban-list__main");
    const name = createElement("div", "ban-list__name");
    name.appendChild(createElement("strong", "", item.username || "Unknown user"));

    if (item.display_name) {
      name.appendChild(createElement("small", "", item.display_name));
    }

    main.appendChild(name);
    main.appendChild(createElement("p", "ban-list__reason", item.public_reason || "Нарушаване на правилата на BG-GAMER"));
    identity.appendChild(main);
    return identity;
  }

  function createSummaryBlock(item) {
    const aside = createElement("div", "ban-list__aside");

    const status = createElement("span", "ban-list__status", item.status_label || "Активен бан");
    status.dataset.status = item.status || "active";
    aside.appendChild(status);

    const summary = createElement("div", "ban-list__summary");
    summary.appendChild(createElement("span", "ban-list__summary-item", item.masked_discord_id || "••••••••0000"));
    summary.appendChild(createElement("span", "ban-list__summary-item", stripDateLabel(item.banned_at_display)));
    summary.appendChild(createElement("span", "ban-list__summary-item", item.public_reference || "BG-BAN"));
    aside.appendChild(summary);

    return aside;
  }

  function createCaret() {
    const caret = createElement("span", "ban-list__caret");
    caret.setAttribute("aria-hidden", "true");
    caret.textContent = "⌄";
    return caret;
  }

  function createDetailStack(item) {
    const stack = createElement("div", "ban-list__stack");

    const reason = createElement("div", "ban-list__detail-block");
    reason.appendChild(createElement("span", "", "Публична причина"));
    reason.appendChild(createElement("strong", "", item.public_reason || "Нарушаване на правилата на BG-GAMER"));
    stack.appendChild(reason);

    const note = createElement("div", "ban-list__detail-block");
    note.appendChild(createElement("span", "", "Как работи appeal"));
    note.appendChild(createElement("strong", "", "Отваря се с public reference и изисква съвпадащ Discord ID за заявката."));
    stack.appendChild(note);

    return stack;
  }

  function createDetailSidebar(item) {
    const stack = createElement("div", "ban-list__stack");
    const meta = createElement("div", "ban-list__detail-grid");

    meta.appendChild(createMetaCard("Masked ID", item.masked_discord_id || "••••••••0000"));
    meta.appendChild(createMetaCard("Дата", stripDateLabel(item.banned_at_display)));
    meta.appendChild(createMetaCard("Appeal", item.appeal_status_label || "Няма подадено обжалване"));
    meta.appendChild(createMetaCard("Reference", item.public_reference || "BG-BAN"));

    if (item.expires_at) {
      meta.appendChild(createMetaCard("Изтича", formatDate(item.expires_at)));
    }

    stack.appendChild(meta);

    const footer = createElement("div", "ban-list__footer");
    footer.appendChild(createElement("p", "ban-list__note", "Кликнете върху ред за бърз преглед. Отворете appeal само ако е този запис."));

    const actions = createElement("div", "ban-list__actions");
    const link = createElement("a", "btn btn-brand ban-list__cta", "Обжалвай бана");
    link.href = item.appeal_url || "../appeal/";
    actions.appendChild(link);
    footer.appendChild(actions);
    stack.appendChild(footer);

    return stack;
  }

  function createMetaCard(label, value) {
    const card = document.createElement("dl");
    card.appendChild(createElement("dt", "", label));
    card.appendChild(createElement("dd", "", value || "Няма данни"));
    return card;
  }

  function closeOpenRows(exceptArticle) {
    elements.grid.querySelectorAll(".ban-list__item.is-open").forEach((article) => {
      if (article === exceptArticle) {
        return;
      }

      const toggle = article.querySelector(".ban-list__toggle");
      const details = article.querySelector(".ban-list__details");

      if (!toggle || !details) {
        return;
      }

      setExpandedRow(article, toggle, details, false);
    });
  }

  function setExpandedRow(article, toggle, details, expanded) {
    article.classList.toggle("is-open", expanded);
    toggle.setAttribute("aria-expanded", expanded ? "true" : "false");
    details.hidden = !expanded;
  }

  function renderSkeletons(count) {
    hideState();
    const fragment = document.createDocumentFragment();

    for (let index = 0; index < count; index += 1) {
      const row = createElement("article", "ban-list__item ban-skeleton");
      const inner = createElement("div", "ban-list__toggle");
      const skeleton = createElement("div", "ban-skeleton__row");
      skeleton.appendChild(createElement("div", "ban-skeleton__line ban-skeleton__line--md"));
      skeleton.appendChild(createElement("div", "ban-skeleton__line ban-skeleton__line--lg"));
      skeleton.appendChild(createElement("div", "ban-skeleton__chip"));
      inner.appendChild(skeleton);
      row.appendChild(inner);
      fragment.appendChild(row);
    }

    elements.grid.replaceChildren(fragment);
  }

  function showState(options) {
    const wrapper = createElement("div", "ban-state__inner");
    wrapper.appendChild(createElement("h3", "", options.title));
    wrapper.appendChild(createElement("p", "", options.message));

    if (options.actionLabel && typeof options.actionHandler === "function") {
      const actions = createElement("div", "ban-state__actions");
      const button = createElement("button", "btn btn-ghost", options.actionLabel);
      button.type = "button";
      button.addEventListener("click", options.actionHandler);
      actions.appendChild(button);
      wrapper.appendChild(actions);
    }

    elements.state.replaceChildren(wrapper);
    elements.state.classList.add("is-visible");
  }

  function hideState() {
    elements.state.replaceChildren();
    elements.state.classList.remove("is-visible");
  }

  function updateSummary(payload) {
    if (!payload) {
      elements.summary.textContent = "Публичният ban feed не можа да бъде зареден.";
      return;
    }

    const total = payload.summary.filtered_total;

    if (!total) {
      elements.summary.textContent = state.query || state.dateFrom || state.dateTo || state.status !== "active"
        ? "Няма съвпадения за текущите филтри."
        : "Няма активни записи в публичния ban feed.";
      return;
    }

    const baseText = total === 1
      ? "1 запис е достъпен за преглед."
      : `${total} записа са достъпни за преглед.`;

    elements.summary.textContent = `${baseText} Кликнете върху ред за детайли и appeal.`;
  }

  function updatePagination(forceDisabled) {
    const disabled = Boolean(forceDisabled) || state.filteredTotal === 0;
    elements.prev.disabled = disabled || state.page <= 1;
    elements.next.disabled = disabled || state.page >= state.totalPages;
    elements.pageStatus.textContent = `Страница ${state.page} от ${state.totalPages}`;
    elements.pageButtons.replaceChildren();

    if (disabled) {
      return;
    }

    buildPageModel(state.page, state.totalPages).forEach((entry) => {
      if (entry === "...") {
        elements.pageButtons.appendChild(createElement("span", "ban-pagination__ellipsis", "..."));
        return;
      }

      const button = createElement("button", "ban-pagination__page", String(entry));
      button.type = "button";

      if (entry === state.page) {
        button.classList.add("is-active");
        button.setAttribute("aria-current", "page");
      } else {
        button.addEventListener("click", () => {
          navigateTo(entry, entry > state.page ? "next" : "prev");
        });
      }

      elements.pageButtons.appendChild(button);
    });
  }

  function updateHero(lastSyncedAt) {
    elements.heroActive.textContent = formatCompactNumber(state.activeTotal);
    elements.panelActive.textContent = formatCompactNumber(state.activeTotal);
    elements.syncTime.textContent = lastSyncedAt ? formatDate(lastSyncedAt) : "Няма sync";
  }

  function navigateTo(nextPage, direction) {
    if (nextPage < 1 || nextPage > state.totalPages || nextPage === state.page) {
      return;
    }

    state.page = nextPage;
    state.direction = direction;
    state.openReference = null;
    loadBans({ historyMode: "push" });
  }

  function hydrateFromUrl() {
    const url = new URL(window.location.href);
    state.page = parsePositiveInt(url.searchParams.get("page"), defaultState.page);
    state.query = url.searchParams.get("q") ?? defaultState.query;
    state.status = url.searchParams.get("status") ?? defaultState.status;
    state.dateFrom = url.searchParams.get("date_from") ?? defaultState.dateFrom;
    state.dateTo = url.searchParams.get("date_to") ?? defaultState.dateTo;
  }

  function updateHistory(mode) {
    if (mode === "skip") {
      return;
    }

    const url = new URL(window.location.href);
    url.searchParams.delete("page");
    url.searchParams.delete("q");
    url.searchParams.delete("status");
    url.searchParams.delete("date_from");
    url.searchParams.delete("date_to");

    if (state.page > 1) {
      url.searchParams.set("page", String(state.page));
    }

    if (state.query) {
      url.searchParams.set("q", state.query);
    }

    if (state.status && state.status !== "active") {
      url.searchParams.set("status", state.status);
    }

    if (state.dateFrom) {
      url.searchParams.set("date_from", state.dateFrom);
    }

    if (state.dateTo) {
      url.searchParams.set("date_to", state.dateTo);
    }

    const statePayload = {
      page: state.page,
      q: state.query,
      status: state.status,
      date_from: state.dateFrom,
      date_to: state.dateTo
    };

    if (mode === "push") {
      window.history.pushState(statePayload, "", url);
    } else {
      window.history.replaceState(statePayload, "", url);
    }
  }

  function syncFormFromState() {
    elements.search.value = state.query;
    elements.status.value = state.status;
    elements.dateFrom.value = state.dateFrom;
    elements.dateTo.value = state.dateTo;
    syncStatusPicker();
  }

  function setupStatusPicker() {
    const field = elements.status ? elements.status.closest(".ban-field") : null;

    if (!field || !elements.status || elements.status.options.length < 2) {
      return null;
    }

    const picker = createElement("div", "ban-status-picker");
    const button = createElement("button", "ban-status-picker__button");
    const label = createElement("span", "ban-status-picker__label");
    const caret = createElement("span", "ban-status-picker__caret");
    const menu = createElement("div", "ban-status-picker__menu");
    const menuId = "banStatusPickerMenu";

    button.type = "button";
    button.setAttribute("aria-haspopup", "listbox");
    button.setAttribute("aria-expanded", "false");
    button.setAttribute("aria-controls", menuId);
    button.appendChild(label);
    button.appendChild(caret);

    menu.id = menuId;
    menu.setAttribute("role", "listbox");
    menu.hidden = true;

    Array.from(elements.status.options).forEach((option) => {
      const optionButton = createElement("button", "ban-status-picker__option", option.textContent || option.value);
      optionButton.type = "button";
      optionButton.dataset.value = option.value;
      optionButton.setAttribute("role", "option");
      optionButton.setAttribute("aria-selected", option.selected ? "true" : "false");
      optionButton.addEventListener("click", () => {
        if (elements.status.value !== option.value) {
          elements.status.value = option.value;
          elements.status.dispatchEvent(new Event("change", { bubbles: true }));
        }

        closeStatusPicker();
        button.focus();
      });
      menu.appendChild(optionButton);
    });

    button.addEventListener("click", () => {
      toggleStatusPicker();
    });

    button.addEventListener("keydown", (event) => {
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        openStatusPicker();
        focusStatusOption(elements.status.value);
      }
    });

    menu.addEventListener("keydown", (event) => {
      const options = Array.from(menu.querySelectorAll(".ban-status-picker__option"));
      const activeIndex = options.findIndex((option) => option === document.activeElement);

      if (event.key === "Escape") {
        event.preventDefault();
        closeStatusPicker();
        button.focus();
        return;
      }

      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();

        if (!options.length) {
          return;
        }

        const step = event.key === "ArrowDown" ? 1 : -1;
        const startIndex = activeIndex >= 0 ? activeIndex : options.findIndex((option) => option.dataset.value === elements.status.value);
        const nextIndex = (startIndex + step + options.length) % options.length;
        options[nextIndex].focus();
      }
    });

    document.addEventListener("click", (event) => {
      if (!picker.contains(event.target)) {
        closeStatusPicker();
      }
    });

    elements.status.classList.add("ban-field__native-select");
    elements.status.tabIndex = -1;
    picker.appendChild(button);
    picker.appendChild(menu);
    field.appendChild(picker);

    syncStatusPicker();

    return {
      button,
      label,
      menu,
      picker
    };
  }

  function syncStatusPicker() {
    if (!statusPicker) {
      return;
    }

    const selectedOption = elements.status.options[elements.status.selectedIndex];
    const selectedLabel = selectedOption ? selectedOption.textContent || selectedOption.value : "";
    statusPicker.label.textContent = selectedLabel;

    Array.from(statusPicker.menu.querySelectorAll(".ban-status-picker__option")).forEach((option) => {
      const isActive = option.dataset.value === elements.status.value;
      option.classList.toggle("is-active", isActive);
      option.setAttribute("aria-selected", isActive ? "true" : "false");
    });
  }

  function toggleStatusPicker() {
    if (!statusPicker) {
      return;
    }

    if (statusPicker.picker.classList.contains("is-open")) {
      closeStatusPicker();
    } else {
      openStatusPicker();
    }
  }

  function openStatusPicker() {
    if (!statusPicker) {
      return;
    }

    statusPicker.picker.classList.add("is-open");
    statusPicker.button.setAttribute("aria-expanded", "true");
    statusPicker.menu.hidden = false;
  }

  function closeStatusPicker() {
    if (!statusPicker) {
      return;
    }

    statusPicker.picker.classList.remove("is-open");
    statusPicker.button.setAttribute("aria-expanded", "false");
    statusPicker.menu.hidden = true;
  }

  function focusStatusOption(value) {
    if (!statusPicker) {
      return;
    }

    const target = Array.from(statusPicker.menu.querySelectorAll(".ban-status-picker__option"))
      .find((option) => option.dataset.value === value);

    if (target) {
      target.focus();
    }
  }

  function setApiState(status, label) {
    elements.apiState.dataset.state = status;
    elements.apiState.textContent = label;
  }

  function animateListIn() {
    if (prefersReducedMotion.matches) {
      return;
    }

    elements.grid.dataset.direction = state.direction;
    elements.grid.classList.add("is-transitioning-in");

    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        elements.grid.classList.remove("is-transitioning-in");
      });
    });
  }

  async function animateListOut() {
    if (prefersReducedMotion.matches || !elements.grid.childElementCount) {
      return;
    }

    elements.grid.dataset.direction = state.direction;
    elements.grid.classList.add("is-transitioning-out");

    await new Promise((resolve) => {
      window.setTimeout(resolve, 160);
    });

    elements.grid.classList.remove("is-transitioning-out");
  }

  function createRevealObserver() {
    if (!("IntersectionObserver" in window)) {
      return null;
    }

    return new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) {
          return;
        }

        entry.target.classList.add("is-visible");
        revealObserver.unobserve(entry.target);
      });
    }, {
      threshold: 0.05,
      rootMargin: "0px 0px -10% 0px"
    });
  }

  function observeReveals(scope) {
    if (!revealObserver) {
      return;
    }

    scope.querySelectorAll("[data-reveal]").forEach((node) => {
      revealObserver.observe(node);
    });
  }

  function buildPageModel(currentPage, totalPages) {
    const pages = [];
    const start = Math.max(1, currentPage - 1);
    const end = Math.min(totalPages, currentPage + 1);

    if (start > 1) {
      pages.push(1);
    }

    if (start > 2) {
      pages.push("...");
    }

    for (let page = start; page <= end; page += 1) {
      pages.push(page);
    }

    if (end < totalPages - 1) {
      pages.push("...");
    }

    if (end < totalPages) {
      pages.push(totalPages);
    }

    return pages;
  }

  function getPerPage() {
    if (window.innerWidth < 576) {
      return 5;
    }

    if (window.innerWidth < 992) {
      return 6;
    }

    return 8;
  }

  function formatDate(value) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
      return "Няма дата";
    }

    return new Intl.DateTimeFormat("bg-BG", {
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit"
    }).format(date);
  }

  function formatCompactNumber(value) {
    return new Intl.NumberFormat("bg-BG").format(Number(value || 0));
  }

  function stripDateLabel(value) {
    if (!value) {
      return "Не е налична";
    }

    return String(value).replace(/^Дата:\s*/u, "");
  }

  function parsePositiveInt(value, fallbackValue) {
    const parsed = Number.parseInt(String(value || ""), 10);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallbackValue;
  }

  function createElement(tagName, className, text) {
    const element = document.createElement(tagName);

    if (className) {
      element.className = className;
    }

    if (typeof text === "string") {
      element.textContent = text;
    }

    return element;
  }

  function isTypingTarget(target) {
    if (!(target instanceof HTMLElement)) {
      return false;
    }

    const tag = target.tagName.toLowerCase();
    return tag === "input" || tag === "textarea" || tag === "select" || target.isContentEditable;
  }
})();
