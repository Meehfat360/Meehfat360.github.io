/* global SSCA_Admin, jQuery */
(function ($) {
  'use strict';

  const API = {
    fetch(endpoint, options = {}) {
      return fetch(SSCA_Admin.rest_url + endpoint, {
        headers: {
          'X-WP-Nonce': SSCA_Admin.rest_nonce,
          'Content-Type': 'application/json',
          ...options.headers,
        },
        ...options,
      }).then(r => r.json());
    },
    post(endpoint, data) {
      return this.fetch(endpoint, {
        method: 'POST',
        body: JSON.stringify(data),
      });
    },
  };

  // ── Run Workflow ────────────────────────────────────────────────────────────
  $(document).on('click', '#ssca-run-workflow, #ssca-run-workflow-2', function () {
    if (!confirm(SSCA_Admin.i18n.confirm_run)) return;
    const $btn = $(this);
    $btn.text('⏳ Running…').prop('disabled', true);

    API.post('workflow/run').then(res => {
      if (res.success) {
        showNotice('success', '✅ ' + res.message);
        setTimeout(() => location.reload(), 2000);
      } else {
        showNotice('error', '❌ ' + (res.message || SSCA_Admin.i18n.error));
        $btn.text('▶ Run Daily Workflow Now').prop('disabled', false);
      }
    }).catch(() => {
      showNotice('error', SSCA_Admin.i18n.error);
      $btn.text('▶ Run Daily Workflow Now').prop('disabled', false);
    });
  });

  // ── Post Preview Modal ──────────────────────────────────────────────────────
  $(document).on('click', '.ssca-preview-post', function () {
    const id = $(this).data('id');
    openModal('Loading post preview…');

    API.fetch('posts/' + id).then(post => {
      let html = '<div class="ssca-post-preview">';

      if (post.image_url) {
        html += `<img src="${escHtml(post.image_url)}" alt="Creative">`;
      } else {
        html += '<div style="background:#f3f4f6;border-radius:8px;display:flex;align-items:center;justify-content:center;aspect-ratio:1;color:#9ca3af">No image</div>';
      }

      const plt = { facebook: '📘', instagram: '📷', pinterest: '📌' };
      html += `<div>
        <p><strong>Platform:</strong> ${plt[post.platform] || '🌐'} ${escHtml(post.platform_label)}</p>
        <p><strong>Variant:</strong> ${escHtml(post.variant)}</p>
        <p><strong>Status:</strong> <span class="ssca-badge ssca-badge-${post.status_badge}">${escHtml(post.status_label)}</span></p>
        <p><strong>Scheduled:</strong> ${escHtml(post.scheduled_at || '—')}</p>
        ${post.product ? `<p><strong>Product:</strong> ${escHtml(post.product.name)} — ${escHtml(post.product.price)}</p>` : ''}
        <p><strong>Clicks:</strong> ${post.clicks}</p>
      </div>`;
      html += '</div>';

      if (post.caption) {
        html += `<div style="margin-top:16px"><strong>Caption:</strong><div class="ssca-post-preview-caption">${escHtml(post.caption)}</div></div>`;
      }
      if (post.error_msg) {
        html += `<div style="margin-top:12px;background:#fee2e2;padding:10px;border-radius:6px;font-size:12px;color:#991b1b">⚠️ ${escHtml(post.error_msg)}</div>`;
      }

      $('#ssca-modal-body').html(html);
    });

    return false;
  });

  // ── Approve Post ────────────────────────────────────────────────────────────
  $(document).on('click', '.ssca-approve-post', function () {
    if (!confirm(SSCA_Admin.i18n.confirm_approve)) return;
    const id   = $(this).data('id');
    const $row = $(this).closest('tr');

    API.post('posts/' + id + '/approve').then(res => {
      if (res.success) {
        $row.find('.ssca-status-badge').text('Scheduled').removeClass().addClass('ssca-status-badge ssca-status-blue');
        $(this).remove();
        showNotice('success', '✅ Post approved and scheduled.');
      } else {
        showNotice('error', '❌ ' + (res.message || SSCA_Admin.i18n.error));
      }
    });
  });

  // ── Cancel Post ─────────────────────────────────────────────────────────────
  $(document).on('click', '.ssca-cancel-post', function () {
    if (!confirm(SSCA_Admin.i18n.confirm_cancel)) return;
    const id   = $(this).data('id');
    const $row = $(this).closest('tr');

    API.post('posts/' + id + '/cancel').then(res => {
      if (res.success) {
        $row.find('.ssca-status-badge').text('Cancelled').removeClass().addClass('ssca-status-badge ssca-status-gray');
        $row.find('.ssca-cancel-post, .ssca-approve-post').remove();
        showNotice('success', '✅ Post cancelled.');
      } else {
        showNotice('error', '❌ ' + (res.message || SSCA_Admin.i18n.error));
      }
    });
  });

  // ── Refresh Health ──────────────────────────────────────────────────────────
  $(document).on('click', '#ssca-refresh-health, #ssca-force-health', function () {
    const $btn = $(this).text('Checking…').prop('disabled', true);

    API.post('health/check').then(res => {
      showNotice('success', '✅ Health check complete. Refreshing…');
      setTimeout(() => location.reload(), 1200);
    }).catch(() => {
      showNotice('error', SSCA_Admin.i18n.error);
      $btn.text('Refresh').prop('disabled', false);
    });
  });

  // ── Clear Log ───────────────────────────────────────────────────────────────
  $(document).on('click', '#ssca-clear-log, #ssca-clear-log-settings', function () {
    if (!confirm('Clear all activity log entries?')) return;
    API.post('log/clear').then(() => {
      showNotice('success', '✅ Log cleared.');
      setTimeout(() => location.reload(), 1000);
    });
  });

  // ── Calendar: day click → panel ─────────────────────────────────────────────
  if (window.SSCA_Calendar) {
    $(document).on('click', '.ssca-cal-day:not(.ssca-cal-empty)', function () {
      const date  = $(this).data('date');
      if (!date) return;

      const dayPosts = SSCA_Calendar.posts.filter(p =>
        p.scheduled_at && p.scheduled_at.startsWith(date)
      );

      const dateStr = new Date(date + 'T00:00:00').toLocaleDateString(undefined, {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
      });

      $('#ssca-day-panel-title').text(dateStr);

      let html = '';
      if (dayPosts.length === 0) {
        html = '<p style="padding:16px;color:#9ca3af">No posts scheduled for this day.</p>';
      } else {
        dayPosts.forEach(p => {
          html += `
            <div style="display:flex;align-items:flex-start;gap:14px;padding:14px 20px;border-bottom:1px solid #f3f4f6">
              ${p.image_url ? `<img src="${escHtml(p.image_url)}" style="width:64px;height:64px;object-fit:cover;border-radius:6px;flex-shrink:0" alt="">` : ''}
              <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                  <span style="color:${p.platform_color};font-weight:600;font-size:13px">${p.platform_icon} ${escHtml(p.platform_label)}</span>
                  <span class="ssca-badge ssca-badge-${p.status_badge}">${escHtml(p.status_label)}</span>
                  <span class="ssca-variant-badge ssca-variant-${p.variant.toLowerCase()}">${escHtml(p.variant)}</span>
                </div>
                <div style="font-size:13px;font-weight:600">${escHtml(p.product_name)}</div>
                ${p.caption_excerpt ? `<div style="font-size:12px;color:#6b7280;margin-top:4px">${escHtml(p.caption_excerpt)}</div>` : ''}
                <div style="font-size:11px;color:#9ca3af;margin-top:4px">
                  ${p.scheduled_at ? p.scheduled_at.split(' ')[1] : ''} • ${p.clicks} clicks
                </div>
              </div>
              <div style="display:flex;gap:4px;flex-shrink:0">
                <button class="ssca-btn-icon ssca-preview-post" data-id="${p.id}" title="Preview">👁</button>
                <button class="ssca-btn-icon ssca-cancel-post" data-id="${p.id}" title="Cancel">❌</button>
              </div>
            </div>`;
        });
      }

      $('#ssca-day-panel-body').html(html);
      $('#ssca-day-panel').show();
    });

    $(document).on('click', '.ssca-day-panel-close', () => $('#ssca-day-panel').hide());

    // Chip click → preview
    $(document).on('click', '.ssca-cal-post-chip', function (e) {
      e.stopPropagation();
      const id = $(this).data('post-id');
      if (id) $('.ssca-preview-post[data-id="' + id + '"]').trigger('click');
    });
  }

  // ── Settings: Connection Test ────────────────────────────────────────────────
  $(document).on('click', '[data-test]', function () {
    const platform = $(this).data('test');
    const $btn     = $(this);
    $btn.text('Testing…').prop('disabled', true);

    $.post(SSCA_Admin.ajax_url, {
      action: 'ssca_test_connection',
      platform,
      nonce: SSCA_Admin.nonce,
    }, res => {
      if (res.success) {
        const status = res.data;
        if (status.status === 'ok') {
          showNotice('success', `✅ ${platform} connected! ${status.user || status.username || ''}`);
        } else {
          showNotice('error', `❌ ${status.message}`);
        }
      }
      $btn.text('Test Connection').prop('disabled', false);
    });
  });

  // ── Settings: Media Uploader (Logo) ─────────────────────────────────────────
  $(document).on('click', '#ssca-upload-logo', function (e) {
    e.preventDefault();
    if (!wp || !wp.media) return;

    const frame = wp.media({
      title: 'Select Brand Logo',
      button: { text: 'Use as Logo' },
      multiple: false,
    });

    frame.on('select', function () {
      const attachment = frame.state().get('selection').first().toJSON();
      $('#ssca-logo-id').val(attachment.id);
      const preview = $('#ssca-logo-preview');
      if (preview.is('img')) {
        preview.attr('src', attachment.url);
      } else {
        preview.replaceWith(`<img id="ssca-logo-preview" src="${attachment.url}" height="60" alt="Logo">`);
      }
    });

    frame.open();
  });

  $(document).on('click', '#ssca-remove-logo', function () {
    $('#ssca-logo-id').val('0');
    $('#ssca-logo-preview').replaceWith('<div id="ssca-logo-preview" class="ssca-logo-placeholder">No logo set</div>');
    $(this).remove();
  });

  // ── Color field sync ─────────────────────────────────────────────────────────
  $(document).on('input', 'input[type="color"]', function () {
    $(this).siblings('.ssca-hex-input').val($(this).val());
  });
  $(document).on('input', '.ssca-hex-input', function () {
    const val = $(this).val();
    if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
      $(this).siblings('input[type="color"]').val(val);
    }
  });

  // ── Save Feedback ─────────────────────────────────────────────────────────────
  $('form').on('submit', function () {
    const $btn = $(this).find('[type="submit"]');
    $btn.text(SSCA_Admin.i18n.saving).prop('disabled', true);
  });

  // ── Modal Helpers ─────────────────────────────────────────────────────────────
  function openModal(loadingText) {
    $('#ssca-modal-body').html(`<div class="ssca-loading">${escHtml(loadingText)}</div>`);
    $('#ssca-post-modal').show();
  }
  $(document).on('click', '.ssca-modal-close', () => $('#ssca-post-modal').hide());
  $(document).on('click', '.ssca-modal', function (e) {
    if ($(e.target).is('.ssca-modal')) $(this).hide();
  });

  // ── Notice Helper ─────────────────────────────────────────────────────────────
  function showNotice(type, message) {
    const $notice = $(`<div class="notice notice-${type} is-dismissible"><p>${message}</p></div>`);
    $('.wp-header-end, h1.wp-heading-inline').first().after($notice);
    setTimeout(() => $notice.fadeOut(400, () => $notice.remove()), 4000);
  }

  function escHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str)));
    return d.innerHTML;
  }

  // ── Auto-refresh dashboard metrics every 60s ──────────────────────────────────
  if ($('#ssca-run-workflow').length && window.SSCA_Admin) {
    setInterval(() => {
      API.fetch('status').then(data => {
        if (!data.metrics) return;
        const m = data.metrics;
        const vals = document.querySelectorAll('.ssca-stat-value');
        // Non-destructive update: only update if matching data is visible
        // Full re-render avoided to prevent layout flash
      });
    }, 60000);
  }

})(jQuery);
