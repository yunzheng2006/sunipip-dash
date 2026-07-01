/* ============================================================
   可选：纯静态站运行时从 Public API 拉 VIP 等级并渲染
   放到 assets/main.js 末尾或 <script defer> 单独引入
   ----------------------------------------------------
   需要先在管理后台 /settings/api-keys 创建一个 Key，
   勾选 "VIP 等级" scope。把 Key 换到下面的 API_KEY 常量里。
   ============================================================ */

(function () {
  const API_BASE = 'https://admin.sunipip.uk/api/public/v1';
  const API_KEY = 'sk_xxxxxxxxxxxxxxxxxxxx'; // TODO: 替换为真实 Key

  const ICON_MAP = [
    '/img/icons/chart-up.svg',
    '/img/icons/check-star.svg',
    '/img/icons/diamond.svg',
    '/img/icons/rocket.svg',
    '/img/icons/target.svg',
  ];

  function formatDiscount(percent) {
    const n = Number(percent) / 10;
    return Number.isInteger(n) ? String(n) : n.toFixed(1);
  }

  function esc(s) {
    return String(s || '').replace(/[&<>"]/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;',
    }[c]));
  }

  function renderCard(t, idx) {
    const save = 100 - Number(t.discount_percent);
    const color = t.badge_color || '#F7A600';
    const icon = ICON_MAP[idx % ICON_MAP.length];
    const threshold = Number(t.spending_threshold || 0).toLocaleString();
    const topup = Number(t.topup_threshold || 0).toLocaleString();
    const desc = t.description || '达成门槛即永久享折扣，全平台 IP 产品统一生效。';

    return `
      <article class="vip-card" style="--tier-color:${color};">
        <div class="vip-card__icon"><img src="${icon}" alt=""></div>
        <h3 class="vip-card__title">${esc(t.name)}</h3>
        <div class="vip-card__price">
          <span class="vip-card__num">${formatDiscount(t.discount_percent)}</span>
          <span class="vip-card__unit">折</span>
          <span class="vip-card__save">立省 ${save}%</span>
        </div>
        <p class="vip-card__desc">${esc(desc)}</p>
        <ul class="vip-card__features">
          ${Number(t.spending_threshold) > 0 ? `<li>累计消费满 <b>¥${threshold}</b></li>` : ''}
          ${Number(t.topup_threshold) > 0 ? `<li>或单笔充值满 <b>¥${topup}</b></li>` : ''}
          <li>购 IP 立省 <b>${save}%</b>，永久有效</li>
          <li>达成条件满足任一即可升级</li>
        </ul>
        <a class="vip-card__cta" href="https://user.sunipip.uk" target="_blank" rel="noopener">
          立即开始 <span>→</span>
        </a>
      </article>
    `;
  }

  async function loadTiers() {
    const grid = document.querySelector('#vip-tiers .vip-grid');
    if (!grid) return;

    try {
      const res = await fetch(`${API_BASE}/vip-tiers`, {
        headers: { 'X-API-Key': API_KEY },
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const { data } = await res.json();
      const tiers = (data && data.tiers) || [];
      if (!tiers.length) return;

      grid.innerHTML = tiers
        .sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0))
        .map((t, i) => renderCard(t, i))
        .join('');
    } catch (e) {
      console.warn('VIP tiers load failed:', e);
      // 失败时保留 HTML 里的静态 fallback
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadTiers);
  } else {
    loadTiers();
  }
})();
