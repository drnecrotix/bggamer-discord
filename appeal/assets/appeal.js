(function () {
  const form = document.getElementById("appealForm");
  const attachmentInput = document.getElementById("appealAttachment");
  const attachmentName = document.getElementById("appealAttachmentName");
  const submitButton = document.getElementById("appealSubmitButton");
  const counters = Array.from(document.querySelectorAll("textarea[data-max-length]"));

  observeReveals(document);
  bindCounters();
  bindAttachmentPreview();
  bindSubmitState();
  focusFirstIssue();

  function bindCounters() {
    counters.forEach((textarea) => {
      const counter = textarea.closest(".appeal-field")?.querySelector(".appeal-counter");
      const maxLength = Number.parseInt(textarea.dataset.maxLength || textarea.getAttribute("maxlength") || "0", 10);

      if (!counter || !Number.isFinite(maxLength) || maxLength <= 0) {
        return;
      }

      const update = () => {
        counter.textContent = `${textarea.value.length} / ${maxLength}`;
      };

      textarea.addEventListener("input", update);
      update();
    });
  }

  function bindAttachmentPreview() {
    if (!attachmentInput || !attachmentName) {
      return;
    }

    attachmentInput.addEventListener("change", () => {
      const file = attachmentInput.files && attachmentInput.files[0];
      attachmentName.textContent = file ? file.name : "Няма избран файл";
    });
  }

  function bindSubmitState() {
    if (!form || !submitButton) {
      return;
    }

    form.addEventListener("submit", () => {
      submitButton.disabled = true;
      submitButton.classList.add("appeal-submit--pending");
      submitButton.textContent = "Изпращане...";
    });
  }

  function focusFirstIssue() {
    const target = document.querySelector(".appeal-alert--error, .appeal-field.has-error input, .appeal-field.has-error textarea, .appeal-check.has-error input");

    if (!(target instanceof HTMLElement)) {
      return;
    }

    target.scrollIntoView({
      behavior: "smooth",
      block: "center"
    });

    if (typeof target.focus === "function") {
      target.focus({ preventScroll: true });
    }
  }

  function observeReveals(scope) {
    if (!("IntersectionObserver" in window)) {
      return;
    }

    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) {
          return;
        }

        entry.target.classList.add("is-visible");
        observer.unobserve(entry.target);
      });
    }, {
      threshold: 0.05,
      rootMargin: "0px 0px -10% 0px"
    });

    scope.querySelectorAll("[data-reveal]").forEach((node) => {
      observer.observe(node);
    });
  }
})();
