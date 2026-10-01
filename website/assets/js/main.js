/* Standalone vanilla JavaScript. No packages, CDN requests or build step. */
(() => {
  'use strict';

  // Set an absolute URL here if the panel is hosted on another domain.
  const PANEL_URL = '/login';

  const translations = window.GlimpseTranslations;
  const languagePicker = document.getElementById('language-picker');
  const languageToggle = document.getElementById('language-toggle');
  const languageMenu = document.getElementById('language-menu');
  const languageOptions = [...languageMenu.querySelectorAll('[data-language]')];
  const storageKey = 'glimpse.website.language';
  let language = 'en';
  // Keep references to text nodes so changing languages preserves icons, layout and state.
  const translatedText = [...document.querySelectorAll('[data-i18n]')].flatMap(element => {
    const keys = element.dataset.i18n.split(' ');
    return [...element.childNodes]
      .filter(node => node.nodeType === Node.TEXT_NODE && node.textContent.trim())
      .map((node, index) => ({
        node, key: keys[index],
        before: node.textContent.match(/^\s*/)[0],
        after: node.textContent.match(/\s*$/)[0],
      }));
  });
  // Text written by the interactive parts; each renderer runs again when the language changes.
  const renderers = [];

  function t(key, values = {}) {
    let text = translations[language][key] ?? translations.en[key];
    for (const [name, value] of Object.entries(values)) text = text.replaceAll(`{${name}}`, value);
    return text;
  }

  function setLanguage(value) {
    language = Object.hasOwn(translations, value) ? value : 'en';
    document.documentElement.lang = language;
    document.getElementById('language-current').textContent = language.toUpperCase();
    languageOptions.forEach(option => option.setAttribute('aria-checked', String(option.dataset.language === language)));
    translatedText.forEach(({node, key, before, after}) => {
      node.textContent = before + t(key) + after;
    });
    document.querySelectorAll('[data-i18n-aria]').forEach(element => {
      element.setAttribute('aria-label', t(element.dataset.i18nAria));
    });
    document.querySelector('meta[name="description"]').content = t('page.description');
    menu.setAttribute('aria-label', t(menu.getAttribute('aria-expanded') === 'true' ? 'nav.close' : 'nav.open'));
    renderers.forEach(render => render());
    announcement.textContent = '';
  }

  const icons = {
    eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    'arrow-right': '<path d="M4 12h16m-6-6 6 6-6 6"/>',
    'arrow-up-right': '<path d="M6 18 18 6M6 6h12v12"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    lock: '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>',
    code: '<path d="m8 6-6 6 6 6m8-12 6 6-6 6m-3-14-2 16"/>',
    branch: '<circle cx="6" cy="5" r="3"/><circle cx="6" cy="19" r="3"/><circle cx="18" cy="5" r="3"/><path d="M6 8v8m12-8a9 9 0 0 1-9 9"/>',
    'chevron-down': '<path d="m6 9 6 6 6-6"/>',
    folder: '<path d="M3 7V5a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/>',
    file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Zm0 0v6h6M8 13h8m-8 4h5"/>',
    'file-code': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Zm0 0v6h6m-10 4-3 3 3 3m4-6 3 3-3 3"/>',
    shield: '<path d="M12 2 3 6v6c0 5 9 10 9 10s9-5 9-10V6Z"/><path d="m8 12 3 3 5-6"/>',
    history: '<path d="M3 11a9 9 0 1 1 2 7M3 4v7h7m2-4v5l3 2"/>',
    commit: '<path d="M2 12h6m8 0h6"/><circle cx="12" cy="12" r="4"/>',
    monitor: '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8m-4-4v4"/>',
    sparkles: '<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5ZM3 2v4M1 4h4m15 14v4m-2-2h4"/>',
    link: '<path d="m10 13 4-4m-5 6-2 2a4 4 0 0 1-6-6l4-4a4 4 0 0 1 6 0m2 2 2-2a4 4 0 0 1 6 6l-4 4a4 4 0 0 1-6 0"/>',
    zap: '<path d="m13 2-10 12h8l-1 8 11-13h-8l1-7Z"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
    refresh: '<path d="M21 12a9 9 0 0 1-15.5 6.2M3 12A9 9 0 0 1 18.5 5.8M21 4v5h-5M3 20v-5h5"/>',
    download: '<path d="M12 3v12m-5-5 5 5 5-5M4 21h16"/>',
    layers: '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 13 9 5 9-5"/>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    close: '<path d="m6 6 12 12M6 18 18 6"/>',
  };

  function iconMarkup(name) {
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${icons[name] || ''}</svg>`;
  }
  document.querySelectorAll('[data-icon]').forEach(element => {
    element.outerHTML = iconMarkup(element.dataset.icon);
  });
  // Builds an icon followed by text, for parts that are written from script.
  function withIcon(element, icon, text) {
    element.innerHTML = iconMarkup(icon);
    element.append(text);
  }
  // Sign-in lives on the same domain as this page; a local file preview has no panel to open.
  document.querySelectorAll('[data-panel-link]').forEach(link => {
    link.href = PANEL_URL;
  });
  document.getElementById('year').textContent = new Date().getFullYear();

  const header = document.getElementById('header');
  const markScrolled = () => header.classList.toggle('scrolled', window.scrollY > 8);
  window.addEventListener('scroll', markScrolled, {passive: true});
  markScrolled();

  // Mobile navigation is also keyboard accessible and closes on outside clicks.
  const menu = document.getElementById('menu-toggle');
  const navigation = document.getElementById('navigation');
  function setMenu(open) {
    if (open) setLanguageMenu(false);
    navigation.classList.toggle('open', open);
    menu.setAttribute('aria-expanded', String(open));
    menu.setAttribute('aria-label', t(open ? 'nav.close' : 'nav.open'));
    menu.innerHTML = iconMarkup(open ? 'close' : 'menu');
  }
  menu.addEventListener('click', () => setMenu(menu.getAttribute('aria-expanded') !== 'true'));
  navigation.addEventListener('click', event => {
    if (event.target.closest('a')) setMenu(false);
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (menu.getAttribute('aria-expanded') === 'true') {
      setMenu(false);
      menu.focus();
    }
    if (!branchMenu.hidden) {
      setBranchMenu(false);
      branchToggle.focus();
    }
  });
  document.addEventListener('click', event => {
    const path = event.composedPath();
    if (!path.includes(navigation) && !path.includes(menu)) setMenu(false);
    if (!path.includes(branchMenu) && !path.includes(branchToggle)) setBranchMenu(false);
  });
  window.matchMedia('(min-width: 861px)').addEventListener('change', event => {
    if (event.matches) setMenu(false);
  });

  // The section in view is marked in the navigation.
  const navigationLinks = [...navigation.querySelectorAll('a')];
  const sectionObserver = new IntersectionObserver(entries => entries.forEach(entry => {
    navigationLinks.find(link => link.hash === `#${entry.target.id}`).classList.toggle('current', entry.isIntersecting);
  }), {rootMargin: '-45% 0px -50% 0px'});
  navigationLinks.forEach(link => sectionObserver.observe(document.querySelector(link.hash)));

  // Illustrative content only; the landing page never requests private repository data.
  const files = {
    welcome: {
      name: 'welcome.ts', type: 'TypeScript',
      content: document.getElementById('demo-code').textContent,
    },
    readme: {
      name: 'README.md', type: 'Markdown',
      content: '# Your next big idea\n\nA private project. A shared perspective.\n\n## Made for a closer look\n\n- Explore the project structure\n- Read code with syntax highlighting\n- Follow commits and changes\n- Preview one file at a time\n\n## Access\n\nRead-only. No GitHub or GitLab account required.\nShared with Glimpse.',
    },
    config: {
      name: 'config.json', type: 'JSON',
      content: '{\n  "project": "your-next-big-idea",\n  "branch": "main",\n  "sharing": {\n    "enabled": true,\n    "access": "read-only",\n    "history": true\n  },\n  "madeWith": "Glimpse"\n}',
    },
  };
  let currentFile = 'welcome';
  const codeView = document.getElementById('code-view');
  const historyView = document.getElementById('history-view');
  const code = document.getElementById('demo-code');
  const announcement = document.getElementById('demo-announcement');

  function showView(view, announce = true) {
    codeView.hidden = view !== 'code';
    historyView.hidden = view !== 'history';
    document.querySelectorAll('[data-view]').forEach(button => {
      const active = button.dataset.view === view;
      button.classList.toggle('active', active);
      button.setAttribute('aria-pressed', String(active));
    });
    document.getElementById('file-type').textContent = view === 'code' ? files[currentFile].type : t('demo.historyType');
    if (announce) announcement.textContent = view === 'code' ? t('demo.fileOpened', {file: files[currentFile].name}) : t('demo.historyOpened');
  }

  function showFile(key, announce = true) {
    currentFile = key;
    const file = files[key];
    document.getElementById('file-name').textContent = file.name;
    codeView.setAttribute('aria-label', t('demo.codeLabel', {file: file.name}));
    code.replaceChildren();
    file.content.split('\n').forEach((line, index) => {
      const row = document.createElement('span');
      row.className = 'code-line';
      const number = document.createElement('span');
      number.className = 'line-number';
      number.setAttribute('aria-hidden', 'true');
      number.textContent = index + 1;
      const content = document.createElement('span');
      if (line.trim().startsWith('//')) {
        content.className = 'token-comment';
        content.textContent = line;
      } else {
        line.split(/('[^']*'|"[^"]*"|\b(?:const|import|from|new|await|true|Infinity)\b)/g).forEach(part => {
          const token = document.createElement('span');
          token.textContent = part;
          if (/^['"]/.test(part)) token.className = 'token-string';
          else if (/^(const|import|from|new|await|true|Infinity)$/.test(part)) token.className = 'token-keyword';
          content.append(token);
        });
      }
      row.append(number, content);
      code.append(row);
    });
    document.querySelectorAll('[data-file]').forEach(button => {
      const active = button.dataset.file === key;
      button.classList.toggle('selected', active);
      button.setAttribute('aria-pressed', String(active));
    });
    codeView.scrollTop = 0;
    codeView.scrollLeft = 0;
    showView('code', announce);
  }
  document.querySelectorAll('[data-file]').forEach(button => button.addEventListener('click', () => showFile(button.dataset.file)));
  document.querySelectorAll('[data-view]').forEach(button => button.addEventListener('click', () => showView(button.dataset.view)));
  showFile('welcome', false);
  renderers.push(() => {
    codeView.setAttribute('aria-label', t('demo.codeLabel', {file: files[currentFile].name}));
    showView(codeView.hidden ? 'history' : 'code', false);
  });

  // The branch menu shows that a link only opens the branches it allows.
  const branchToggle = document.getElementById('branch-toggle');
  const branchMenu = document.getElementById('branch-menu');
  function setBranchMenu(open) {
    branchMenu.hidden = !open;
    branchToggle.setAttribute('aria-expanded', String(open));
  }
  branchToggle.addEventListener('click', () => setBranchMenu(branchMenu.hidden));

  // Copy buttons copy the example link they sit next to.
  const copyButtons = [...document.querySelectorAll('[data-copy]')];
  function renderCopyButton(button) {
    const copied = button.classList.contains('copied');
    withIcon(button, copied ? 'check' : 'copy', t(copied ? 'copy.done' : 'copy.link'));
  }
  copyButtons.forEach(button => button.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(button.dataset.copy);
    } catch {
      return; // The clipboard can be unavailable outside a secure context; the link stays visible.
    }
    button.classList.add('copied');
    renderCopyButton(button);
    clearTimeout(button.copyTimer);
    button.copyTimer = setTimeout(() => {
      button.classList.remove('copied');
      renderCopyButton(button);
    }, 1600);
  }));
  renderers.push(() => copyButtons.forEach(renderCopyButton));

  document.querySelectorAll('.branch-row .switch').forEach(toggle => toggle.addEventListener('click', () => {
    toggle.setAttribute('aria-checked', String(toggle.getAttribute('aria-checked') !== 'true'));
  }));

  const refreshButton = document.getElementById('refresh-button');
  const refreshStatus = document.getElementById('refresh-status');
  let refreshed = false;
  function renderRefresh() {
    const [beforeRevision, afterRevision] = t(refreshed ? 'speed.upToDate' : 'speed.showing').split('{revision}');
    const revision = document.createElement('code');
    revision.textContent = 'a7e32f1';
    refreshStatus.replaceChildren(beforeRevision, revision, afterRevision ?? '');
  }
  refreshButton.addEventListener('click', () => {
    refreshed = true;
    refreshButton.classList.remove('spin');
    void refreshButton.offsetWidth;
    refreshButton.classList.add('spin');
    renderRefresh();
  });
  renderers.push(renderRefresh);

  // How it works: the steps advance on their own while in view, until the visitor takes over.
  const workflow = document.getElementById('kako-radi');
  const stepList = document.getElementById('steps');
  const steps = [...stepList.querySelectorAll('[role="tab"]')];
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  // Stacked layouts would jump as panels of different heights swap, so only side by side advances.
  const sideBySide = window.matchMedia('(min-width: 1081px)');
  let stepTimer;
  let autoplay = !reducedMotion.matches;
  let workflowVisible = false;
  function selectStep(step, focus = false) {
    steps.forEach(tab => {
      const active = tab === step;
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
      document.getElementById(tab.getAttribute('aria-controls')).hidden = !active;
    });
    if (focus) step.focus();
    scheduleStep();
  }
  function scheduleStep(visible = workflowVisible) {
    clearTimeout(stepTimer);
    const cycling = autoplay && visible && sideBySide.matches;
    stepList.classList.toggle('cycling', cycling);
    if (!cycling) return;
    const next = steps[(steps.findIndex(tab => tab.getAttribute('aria-selected') === 'true') + 1) % steps.length];
    stepTimer = setTimeout(() => selectStep(next), 8000);
  }
  function stopAutoplay() {
    if (!autoplay) return;
    autoplay = false;
    scheduleStep();
  }
  steps.forEach(tab => tab.addEventListener('click', () => {
    stopAutoplay();
    selectStep(tab);
  }));
  stepList.addEventListener('keydown', event => {
    const index = steps.indexOf(document.activeElement);
    let next;
    if (event.key === 'ArrowDown' || event.key === 'ArrowRight') next = (index + 1) % steps.length;
    else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') next = (index + steps.length - 1) % steps.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = steps.length - 1;
    if (next === undefined || index < 0) return;
    event.preventDefault();
    stopAutoplay();
    selectStep(steps[next], true);
  });
  workflow.addEventListener('focusin', stopAutoplay);
  workflow.addEventListener('pointerdown', stopAutoplay);
  new IntersectionObserver(([entry]) => {
    workflowVisible = entry.isIntersecting;
    scheduleStep();
  }, {threshold: .35}).observe(workflow);
  reducedMotion.addEventListener('change', event => {
    if (event.matches) stopAutoplay();
  });
  sideBySide.addEventListener('change', () => scheduleStep());

  document.querySelectorAll('#repository-filter [data-filter]').forEach(button => button.addEventListener('click', () => {
    document.querySelectorAll('#repository-filter [data-filter]').forEach(other => {
      other.classList.toggle('active', other === button);
      other.setAttribute('aria-pressed', String(other === button));
    });
    document.querySelectorAll('#repository-list [data-platform]').forEach(item => {
      item.hidden = button.dataset.filter !== 'all' && item.dataset.platform !== button.dataset.filter;
    });
  }));

  // Link builder: every choice updates the link summary and the visitor view in step three.
  const linkName = document.getElementById('link-name');
  const historySwitch = document.getElementById('history-switch');
  const durationButtons = [...document.querySelectorAll('#duration [data-days]')];
  let days = 7;
  function renderLink() {
    const branches = [...document.querySelectorAll('#branch-options input:checked')].map(input => input.value);
    const history = historySwitch.getAttribute('aria-checked') === 'true';
    const duration = t(`duration.d${days}`);
    document.getElementById('summary-name').textContent = linkName.value.trim() || t('panel2.untitled');
    document.getElementById('summary-expiry').textContent = t('rules.expires', {duration});
    const rules = document.getElementById('link-rules');
    rules.replaceChildren();
    const addRule = (className, icon, text) => {
      const rule = document.createElement('span');
      rule.className = `pill ${className}`;
      withIcon(rule, icon, text);
      rules.append(rule);
    };
    if (branches.length) branches.forEach(branch => addRule('pill-line', 'branch', branch));
    else addRule('pill-red', 'lock', t('rules.noBranch'));
    addRule(history ? 'pill-blue' : 'pill-line', 'history', t(history ? 'rules.historyOn' : 'rules.historyOff'));
    addRule('pill-green', 'shield', 'Read-only');
    document.getElementById('visitor-branch').textContent = branches[0] ?? t('panel3.noBranch');
    document.getElementById('visitor-history').classList.toggle('disabled', !history);
    document.getElementById('share-note').textContent = t(branches.length ? 'panel3.note' : 'panel3.noteBlocked', {duration});
  }
  linkName.addEventListener('input', renderLink);
  document.querySelectorAll('#branch-options input').forEach(input => input.addEventListener('change', renderLink));
  historySwitch.addEventListener('click', () => {
    historySwitch.setAttribute('aria-checked', String(historySwitch.getAttribute('aria-checked') !== 'true'));
    renderLink();
  });
  durationButtons.forEach(button => button.addEventListener('click', () => {
    days = Number(button.dataset.days);
    durationButtons.forEach(other => {
      other.classList.toggle('active', other === button);
      other.setAttribute('aria-pressed', String(other === button));
    });
    renderLink();
  }));
  renderers.push(renderLink);

  // Security example: revoking or pausing changes what the example link opens.
  const accessVisual = document.getElementById('access-visual');
  const pauseSwitch = document.getElementById('pause-switch');
  const revokeButton = document.getElementById('revoke-button');
  let revoked = false;
  function renderAccess() {
    const paused = pauseSwitch.getAttribute('aria-checked') === 'true';
    const state = revoked ? 'revoked' : paused ? 'paused' : 'active';
    accessVisual.classList.toggle('blocked', state !== 'active');
    const status = document.getElementById('access-status');
    status.className = `pill ${{active: 'pill-green', paused: 'pill-amber', revoked: 'pill-red'}[state]}`;
    const dot = document.createElement('span');
    dot.className = 'status-dot';
    status.replaceChildren(dot, t(`access.${state}`));
    revokeButton.textContent = t(revoked ? 'access.restore' : 'access.revoke');
    document.getElementById('access-note').textContent = t({active: 'access.footer', paused: 'access.pausedNote', revoked: 'access.revokedNote'}[state]);
  }
  revokeButton.addEventListener('click', () => {
    revoked = !revoked;
    renderAccess();
  });
  pauseSwitch.addEventListener('click', () => {
    pauseSwitch.setAttribute('aria-checked', String(pauseSwitch.getAttribute('aria-checked') !== 'true'));
    renderAccess();
  });
  renderers.push(renderAccess);

  let savedLanguage = 'en';
  try { savedLanguage = localStorage.getItem(storageKey) || 'en'; } catch { /* Storage may be blocked or unavailable in a local preview. */ }
  setLanguage(savedLanguage);
  selectStep(steps[0]);

  function setLanguageMenu(open, focusIndex) {
    languageMenu.hidden = !open;
    languageToggle.setAttribute('aria-expanded', String(open));
    if (open) {
      setMenu(false);
      const index = focusIndex ?? languageOptions.findIndex(option => option.dataset.language === language);
      languageOptions[index].focus();
    }
  }
  languageToggle.addEventListener('click', () => setLanguageMenu(languageMenu.hidden));
  languageToggle.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      setLanguageMenu(true, event.key === 'ArrowDown' ? 0 : languageOptions.length - 1);
    }
  });
  languageOptions.forEach(option => option.addEventListener('click', () => {
    setLanguage(option.dataset.language);
    try { localStorage.setItem(storageKey, language); } catch { /* Switching still works without persistence. */ }
    setLanguageMenu(false);
    languageToggle.focus();
  }));
  languagePicker.addEventListener('keydown', event => {
    if (languageMenu.hidden) return;
    if (event.key === 'Escape' || event.key === 'Tab') {
      if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); }
      setLanguageMenu(false);
      languageToggle.focus();
      return;
    }
    if (event.target === languageToggle) return;
    const index = languageOptions.indexOf(document.activeElement);
    let next;
    if (event.key === 'ArrowDown') next = (index + 1) % languageOptions.length;
    else if (event.key === 'ArrowUp') next = (index + languageOptions.length - 1) % languageOptions.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = languageOptions.length - 1;
    else {
      // Typing a letter moves to the language whose code starts with it.
      const match = languageOptions.findIndex(option => option.dataset.language.startsWith(event.key.toLowerCase()));
      if (event.key.length === 1 && match >= 0) next = match;
    }
    if (next !== undefined) { event.preventDefault(); languageOptions[next].focus(); }
  });
  document.addEventListener('pointerdown', event => {
    if (!event.composedPath().includes(languagePicker)) setLanguageMenu(false);
  });
  languagePicker.addEventListener('focusout', event => {
    if (!languagePicker.contains(event.relatedTarget)) setLanguageMenu(false);
  });
  languageToggle.disabled = false;
})();
