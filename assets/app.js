/* Theme toggle persistence (mirrors PHP cookie) + tweaks panel niceties */
(() => {
  // No tenemos React: si el server-side ya pintó data-theme, respétalo.
  // Atajos de teclado básicos: ⌘K abre el buscador.
  const searchInput = document.querySelector('.search input');
  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
      e.preventDefault();
      if (searchInput) searchInput.focus();
    }
  });

  // Buscador en vivo global con menú desplegable (autocomplete)
  const searchForm = document.querySelector('.search');
  const searchDropdown = document.getElementById('search-dropdown');

  if (searchForm && searchInput && searchDropdown) {
    let debounceTimer = null;
    let abortController = null;
    let activeIndex = -1;
    let items = [];

    const escapeHtml = (str) => {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    };

    const highlightText = (text, query) => {
      if (!text) return '';
      if (!query) return escapeHtml(text);
      const safeText = escapeHtml(text);
      const escapedQuery = query.trim().replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      if (!escapedQuery) return safeText;
      const regex = new RegExp('(' + escapedQuery + ')', 'gi');
      return safeText.replace(regex, '<span class="search-dropdown-highlight">$1</span>');
    };

    const openDropdown = () => {
      searchDropdown.style.display = 'flex';
    };

    const closeDropdown = () => {
      searchDropdown.style.display = 'none';
      searchDropdown.innerHTML = '';
      activeIndex = -1;
      items = [];
    };

    const updateActiveItem = () => {
      items.forEach((item, idx) => {
        if (idx === activeIndex) {
          item.classList.add('active');
          item.scrollIntoView({ block: 'nearest' });
        } else {
          item.classList.remove('active');
        }
      });
    };

    const renderLoading = () => {
      searchDropdown.innerHTML = `
        <div class="search-dropdown-loading">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin">
            <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
          </svg>
          <span>Buscando...</span>
        </div>
      `;
      openDropdown();
    };

    const renderResults = (data, query) => {
      const podcasts = data.podcasts || [];
      const episodes = data.episodes || [];
      const total = (data.total !== undefined) ? data.total : (podcasts.length + episodes.length);

      if (total === 0) {
        searchDropdown.innerHTML = `
          <div class="search-dropdown-empty">
            No se encontraron podcasts ni episodios para "<strong>${escapeHtml(query)}</strong>"
          </div>
          <div class="search-dropdown-footer">
            <a href="/admin/search?q=${encodeURIComponent(query)}">Ver página de búsqueda →</a>
            <span><kbd>esc</kbd> cerrar</span>
          </div>
        `;
        openDropdown();
        activeIndex = -1;
        items = [];
        return;
      }

      let html = '<div class="search-dropdown-list">';

      if (podcasts.length > 0) {
        html += `<div class="search-dropdown-section-title">Podcasts (${podcasts.length})</div>`;
        podcasts.forEach(p => {
          const thumb = p.cover
            ? `<img class="search-dropdown-item-thumb" src="${escapeHtml(p.cover)}" alt="">`
            : `<div class="search-dropdown-item-thumb" style="background:linear-gradient(135deg, ${escapeHtml(p.color || '#ff5f7e')}, ${escapeHtml(p.color || '#ff5f7e')}99)">${escapeHtml(p.initial || 'PO')}</div>`;
          
          html += `
            <a class="search-dropdown-item" href="${escapeHtml(p.url)}" role="option">
              ${thumb}
              <div class="search-dropdown-item-info">
                <div class="search-dropdown-item-title">${highlightText(p.title, query)}</div>
                <div class="search-dropdown-item-meta">${highlightText(p.author, query)}${p.description ? ' · ' + highlightText(p.description, query) : ''}</div>
              </div>
              <span class="search-dropdown-item-badge">Podcast</span>
            </a>
          `;
        });
      }

      if (episodes.length > 0) {
        html += `<div class="search-dropdown-section-title">Episodios (${episodes.length})</div>`;
        episodes.forEach(ep => {
          const thumb = ep.cover
            ? `<img class="search-dropdown-item-thumb" src="${escapeHtml(ep.cover)}" alt="">`
            : `<div class="search-dropdown-item-thumb" style="background:linear-gradient(135deg, ${escapeHtml(ep.color || '#6b7280')}, ${escapeHtml(ep.color || '#6b7280')}99)">${escapeHtml(ep.initial || 'EP')}</div>`;
          
          let meta = escapeHtml(ep.podcast_title || ep.podcast);
          if (ep.season && ep.episode) meta += ` · S${ep.season}:E${ep.episode}`;
          if (ep.guest) meta += ` · ${escapeHtml(ep.guest)}`;
          if (ep.date) meta += ` · ${escapeHtml(ep.date)}`;

          html += `
            <a class="search-dropdown-item" href="${escapeHtml(ep.url)}" role="option">
              ${thumb}
              <div class="search-dropdown-item-info">
                <div class="search-dropdown-item-title">${highlightText(ep.title, query)}</div>
                <div class="search-dropdown-item-meta">${meta}</div>
              </div>
              <span class="search-dropdown-item-badge">Episodio</span>
            </a>
          `;
        });
      }

      html += '</div>';
      html += `
        <div class="search-dropdown-footer">
          <a href="/admin/search?q=${encodeURIComponent(query)}">Ver todos los resultados (${total}) →</a>
          <span><kbd>↑↓</kbd> navegar <kbd>↵</kbd> abrir <kbd>esc</kbd></span>
        </div>
      `;

      searchDropdown.innerHTML = html;
      openDropdown();
      activeIndex = -1;
      items = Array.from(searchDropdown.querySelectorAll('.search-dropdown-item'));
    };

    const fetchSearch = async (query) => {
      const q = query.trim();
      if (q.length < 2) {
        closeDropdown();
        return;
      }

      if (abortController) {
        abortController.abort();
      }
      abortController = new AbortController();

      renderLoading();

      try {
        const res = await fetch(`/admin/search?ajax=1&q=${encodeURIComponent(q)}`, {
          signal: abortController.signal,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (searchInput.value.trim() !== q) return;
        renderResults(data, q);
      } catch (err) {
        if (err.name === 'AbortError') return;
        searchDropdown.innerHTML = `
          <div class="search-dropdown-empty">
            Error al consultar los resultados de búsqueda.
          </div>
        `;
        openDropdown();
      }
    };

    searchInput.addEventListener('input', (e) => {
      clearTimeout(debounceTimer);
      const val = e.target.value;
      if (val.trim().length < 2) {
        closeDropdown();
        return;
      }
      debounceTimer = setTimeout(() => {
        fetchSearch(val);
      }, 200);
    });

    searchInput.addEventListener('focus', () => {
      const val = searchInput.value.trim();
      if (val.length >= 2 && searchDropdown.innerHTML.trim() !== '') {
        openDropdown();
      }
    });

    searchInput.addEventListener('keydown', (e) => {
      if (searchDropdown.style.display === 'none') {
        if (e.key === 'ArrowDown' && searchInput.value.trim().length >= 2) {
          fetchSearch(searchInput.value);
        }
        return;
      }

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (items.length > 0) {
          activeIndex = (activeIndex + 1) % items.length;
          updateActiveItem();
        }
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (items.length > 0) {
          activeIndex = (activeIndex - 1 + items.length) % items.length;
          updateActiveItem();
        }
      } else if (e.key === 'Enter') {
        if (activeIndex >= 0 && items[activeIndex]) {
          e.preventDefault();
          const targetUrl = items[activeIndex].getAttribute('href');
          if (targetUrl) {
            window.location.href = targetUrl;
          }
        }
      } else if (e.key === 'Escape') {
        e.preventDefault();
        closeDropdown();
      }
    });

    document.addEventListener('click', (e) => {
      if (!searchForm.contains(e.target)) {
        closeDropdown();
      }
    });
  }

  // Toast desde cookie de flash (kp_flash) — se limpia tras mostrar.
  const m = document.cookie.match(/(?:^|;\s*)kp_flash=([^;]+)/);
  if (m) {
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = decodeURIComponent(m[1]);
    document.body.appendChild(toast);
    document.cookie = 'kp_flash=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    setTimeout(() => toast.remove(), 2400);
  }

  // Copiar URL de episodio al portapapeles
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.admin-copy-url-btn');
    if (!btn) return;
    e.preventDefault();
    const url = btn.dataset.url;
    if (!url) return;
    navigator.clipboard.writeText(url).then(() => {
      const label = btn.querySelector('span');
      if (label) {
        const originalText = label.textContent;
        label.textContent = '¡Copiado!';
        btn.style.color = 'var(--accent)';
        setTimeout(() => {
          label.textContent = originalText;
          btn.style.color = '';
        }, 2000);
      }
    }).catch(err => {
      console.error('Error al copiar: ', err);
    });
  });

})();
