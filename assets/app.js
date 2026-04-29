/* ── DA360 Admin Panel — app.js ── */
/* Role: UI + AJAX only. All field definitions, DB logic, and HTML rendering live in PHP. */

// ── Sidebar Toggle ──────────────────────────────────────────────────────────
const sidebarToggle = document.getElementById('sidebarToggle');
if (sidebarToggle) {
  if (localStorage.getItem('da360_sidebar') === 'collapsed') {
    document.body.classList.add('sidebar-collapsed');
  }
  sidebarToggle.addEventListener('click', () => {
    document.body.classList.toggle('sidebar-collapsed');
    localStorage.setItem(
      'da360_sidebar',
      document.body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open'
    );
  });
}

// ── Toast ───────────────────────────────────────────────────────────────────
function showToast(msg, duration = 2500) {
  let toast = document.getElementById('toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'toast';
    document.body.appendChild(toast);
  }
  toast.textContent = msg;
  toast.classList.add('show');
  clearTimeout(toast._timer);
  toast._timer = setTimeout(() => toast.classList.remove('show'), duration);
}

// ── Copy to clipboard ───────────────────────────────────────────────────────
function copyField(btn, text) {
  navigator.clipboard.writeText(text).then(() => {
    btn.textContent = '✓';
    btn.classList.add('copied');
    showToast('Copied to clipboard!');
    setTimeout(() => {
      btn.textContent = '📋';
      btn.classList.remove('copied');
    }, 1800);
  }).catch(() => showToast('Copy failed — try manually.'));
}

// ── Page config map ──────────────────────────────────────────────────────────
// Maps data-page="x" on <main> to the API endpoint and strings for that page.
// To add a new page (schemas, meta, aitools…):
//   1. Add data-page="your-page" to its <main class="main-content">
//   2. Add one entry below — nothing else in app.js needs to change
//   3. Create your_api.php returning { success, html }
const PAGE_CONFIG = {
  'content-manager': {
    api:         '/da360-admin/api.php',
    action:      'get_content_html',
    loadingText: 'Loading content…',
    loadingDesc: 'Fetching all fields from the database.',
    emptyIcon:   '🗂️',
    emptyTitle:  'Nothing to preview yet',
    emptyDesc:   'Choose a course and location above to load all content fields.',
  },
  'faqs': {
    api:         '/da360-admin/faq_api.php',
    action:      'get_faq_html',
    loadingText: 'Loading FAQs…',
    loadingDesc: 'Fetching FAQ categories from the database.',
    emptyIcon:   '❓',
    emptyTitle:  'No FAQs loaded yet',
    emptyDesc:   'Choose a course and location above to load FAQ categories.',
  },
  'schemas': {
    api:         '/da360-admin/schemas_api.php',
    action:      'get_schemas_html',
    loadingText: 'Loading schemas…',
    loadingDesc: 'Fetching schema fields from the database.',
    emptyIcon:   '🧩',
    emptyTitle:  'No schemas loaded yet',
    emptyDesc:   'Choose a course and location above to load schemas.',
  },
  'meta': {
    api:         '/da360-admin/meta_api.php',
    action:      'get_meta_html',
    loadingText: 'Loading meta…',
    loadingDesc: 'Fetching meta fields from the database.',
    emptyIcon:   '🏷️',
    emptyTitle:  'No meta loaded yet',
    emptyDesc:   'Choose a course and location above to load meta fields.',
  },
  'aitools': {
    api:         '/da360-admin/aitools_api.php',
    action:      'get_aitools_html',
    loadingText: 'Loading AI tools…',
    loadingDesc: 'Fetching AI tool entries from the database.',
    emptyIcon:   '🤖',
    emptyTitle:  'No AI tools loaded yet',
    emptyDesc:   'Choose a course and location above to load AI tools.',
  },
};

// ── Shared Selector IIFE ────────────────────────────────────────────────────
// Runs immediately — safe because app.js is loaded at bottom of <body>
// via footer.php, so the DOM is already parsed when this executes.
(function () {
  const courseSelect   = document.getElementById('course-select');
  const locationSelect = document.getElementById('location-select');
  const viewBtn        = document.getElementById('view-btn');
  const resultArea     = document.getElementById('result-area');
  if (!courseSelect) return; // not a selector page — bail out silently

  // Read data-page from <main class="main-content" data-page="...">
  const page   = (document.querySelector('main.main-content') || {}).dataset.page || '';
  const config = PAGE_CONFIG[page];

  if (!config) {
    console.warn('DA360 app.js: unknown data-page="' + page + '". Add an entry to PAGE_CONFIG or set data-page on <main>.');
    return;
  }

  const placeholder = `
    <div class="state-placeholder">
      <span class="big-icon">${config.emptyIcon}</span>
      <h3>${config.emptyTitle}</h3>
      <p>${config.emptyDesc}</p>
    </div>`;

  // ── Step 1: Course changed → fetch locations ──────────────────────────
  courseSelect.addEventListener('change', () => {
    const courseId = courseSelect.value;

    locationSelect.innerHTML = '<option value="">— Select Location —</option>';
    locationSelect.disabled  = true;
    viewBtn.disabled         = true;
    resultArea.innerHTML     = placeholder;

    if (!courseId) return;

    locationSelect.innerHTML = '<option value="">Loading…</option>';

    fetch(`/da360-admin/api.php?action=get_locations&course_id=${encodeURIComponent(courseId)}`)
      .then(r => r.json())
      .then(data => {
        locationSelect.innerHTML = '<option value="">— Select Location —</option>';
        if (data.success && data.locations.length) {
          data.locations.forEach(loc => {
            const opt       = document.createElement('option');
            opt.value       = loc.id;
            opt.textContent = loc.label;
            locationSelect.appendChild(opt);
          });
          locationSelect.disabled = false;
        } else {
          locationSelect.innerHTML = '<option value="">No locations found</option>';
        }
      })
      .catch(() => {
        locationSelect.innerHTML = '<option value="">Failed to load</option>';
        showToast('Could not load locations.');
      });
  });

  // ── Step 2: Location changed → enable View button ────────────────────
  locationSelect.addEventListener('change', () => {
    viewBtn.disabled = !(courseSelect.value && locationSelect.value);
  });

  // ── Step 3: View clicked → call this page's API ───────────────────────
  viewBtn.addEventListener('click', loadPage);

  function loadPage() {
    const courseId   = courseSelect.value;
    const locationId = locationSelect.value;
    if (!courseId || !locationId) return;

    resultArea.innerHTML = `
      <div class="state-placeholder">
        <div class="spinner"></div>
        <h3>${config.loadingText}</h3>
        <p>${config.loadingDesc}</p>
      </div>`;

    const url = `${config.api}?action=${config.action}`
              + `&course_id=${encodeURIComponent(courseId)}`
              + `&location_id=${encodeURIComponent(locationId)}`;

    fetch(url)
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          resultArea.innerHTML = data.html;
          // Execute any JS returned by the API (e.g. FAQ panel listeners)
          // Scripts inside innerHTML are silently ignored by browsers, so APIs
          // return JS separately as data.js and we execute it here.
          if (data.js) new Function(data.js)();
          // Content Manager needs a save handler wired up after render
          if (page === 'content-manager') {
            attachSaveHandler(loadPage);
          }
        } else {
          resultArea.innerHTML = `
            <div class="state-placeholder">
              <span class="big-icon">⚠️</span>
              <h3>Nothing found</h3>
              <p>${data.message || 'No data configured for this combination.'}</p>
            </div>`;
        }
      })
      .catch(() => {
        resultArea.innerHTML = `
          <div class="state-placeholder">
            <span class="big-icon">❌</span>
            <h3>Request failed</h3>
            <p>Could not reach the server.</p>
          </div>`;
      });
  }

  // ── Content Manager only: wire up the save form after it renders ──────
  function attachSaveHandler(onSaveSuccess) {
    const form    = document.getElementById('content-form');
    const saveBtn = document.getElementById('save-btn');
    if (!form || !saveBtn) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();

      saveBtn.disabled    = true;
      saveBtn.textContent = 'Saving…';

      fetch('/da360-admin/api.php?action=save_content', {
        method: 'POST',
        body: new FormData(form),
      })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            showToast('✅ Content saved successfully!');
            onSaveSuccess(); // reload the form with fresh DB values
          } else {
            showToast('❌ ' + (data.message || 'Save failed.'));
            saveBtn.disabled    = false;
            saveBtn.textContent = 'Save Changes';
          }
        })
        .catch(() => {
          showToast('❌ Network error — save failed.');
          saveBtn.disabled    = false;
          saveBtn.textContent = 'Save Changes';
        });
    });
  }

})();
