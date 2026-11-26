// A device counts as online if it's authenticated within this window -
// every authenticated call (sync included, which the app runs every 2
// minutes while open) stamps last_seen_at server-side, so this is a
// real recency signal, not a stored guess. Purely a client-side
// threshold, not persisted anywhere, so it can be tuned without a
// migration.
const ONLINE_WINDOW_MS = 5 * 60 * 1000;
// How often the table re-fetches from the server, separate from
// tickInterval below (which only re-renders the already-fetched data
// once a second for a live countdown - no network call of its own).
const AUTO_REFRESH_MS = 5000;

// Remembered so a page refresh doesn't force retyping the secret, but
// not kept forever - cleared and re-prompted after ADMIN_SECRET_TTL_MS
// of inactivity. The window slides forward on every successful load
// (manual or auto), so it only actually expires once the page has sat
// idle/closed for a full 20 minutes, not 20 minutes after first entry.
const ADMIN_SECRET_KEY = 'nexapos.dashboard.adminSecret';
const ADMIN_SECRET_TTL_MS = 20 * 60 * 1000;

const COPY_ICON_SVG = '<svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="5.5" y="5.5" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.3"/><path d="M3.5 10.5h-1a1 1 0 0 1-1-1v-6a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v1" stroke="currentColor" stroke-width="1.3"/></svg>';
const CHECK_ICON_SVG = '<svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 8.5l3 3 7-7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

let deviceRows = [];
let supportTickets = [];
let openTicketId = null;
const onlineObservedAt = new Map();
let tickInterval = null;
let autoRefreshInterval = null;

function adminSecret() {
  return document.getElementById('adminSecret').value.trim();
}
function showMsg(id, text) {
  const el = document.getElementById(id);
  el.textContent = text;
  el.className = text ? 'msg show err' : 'msg';
}

// A confirmation rather than an error (the same box, in the success colours).
function showOk(id, text) {
  const el = document.getElementById(id);
  el.textContent = text;
  el.className = text ? 'msg show ok' : 'msg';
}

function restoreAdminSecret() {
  try {
    const raw = localStorage.getItem(ADMIN_SECRET_KEY);
    if (!raw) return false;
    const { secret, savedAt } = JSON.parse(raw);
    if (!secret || typeof savedAt !== 'number' || Date.now() - savedAt >= ADMIN_SECRET_TTL_MS) {
      localStorage.removeItem(ADMIN_SECRET_KEY);
      return false;
    }
    document.getElementById('adminSecret').value = secret;
    return true;
  } catch (e) {
    return false;
  }
}

function saveAdminSecret(secret) {
  try {
    localStorage.setItem(ADMIN_SECRET_KEY, JSON.stringify({ secret, savedAt: Date.now() }));
  } catch (e) {
    // localStorage unavailable (private browsing, quota) - not fatal,
    // just means the secret won't survive a refresh this time.
  }
}

async function loadDashboard() {
  const btn = document.getElementById('loadBtn');
  const secret = adminSecret();
  if (!secret) {
    showMsg('loadMsg', 'Enter the admin secret first.');
    return;
  }
  btn.disabled = true;
  showMsg('loadMsg', '');
  try {
    const platformUrl = document.getElementById('platformUrl').value.trim();
    const licenseUrl = document.getElementById('licenseUrl').value.trim();

    // Support should become usable even when the device or license query is slow.
    refreshSupportTickets();
    const [devicesRes, licensesRes] = await Promise.all([
      fetch(platformUrl + '?action=list_all_devices', { headers: { 'X-Admin-Secret': secret } }),
      fetch(licenseUrl + '?action=list_licenses', { headers: { 'X-Admin-Secret': secret } }),
    ]);
    const devicesData = await devicesRes.json();
    const licensesData = await licensesRes.json();

    if (!devicesData.success) throw new Error(devicesData.message || 'Could not load devices from nexapos_platform.');
    if (!licensesData.success) throw new Error(licensesData.message || 'Could not load licenses from nexapos_license.');

    // Devices drive most of the table (a device exists here the instant
    // it registers, license or not) - license info is looked up per
    // device_id and just left blank when there's no match yet. Codes
    // that were issued but never activated on any device have no
    // device_id to join on at all, so they'd otherwise vanish entirely -
    // shown as their own rows instead, newest-first (list_licenses is
    // already ordered that way), so a freshly generated code is visible
    // immediately rather than only appearing once someone activates it.
    //
    // A single device_id can legitimately own MORE THAN ONE license_keys
    // row over its lifetime - revoke doesn't stop that same device from
    // activating a fresh code later, it just leaves the old row behind
    // with revoked=1. When that happens, the row that should represent
    // the device here is whichever is actually current: not-revoked
    // always beats revoked, and among rows in the same revoked state the
    // more recently activated one wins. licenseRank() below turns that
    // into a single comparable number.
    const licenseByDevice = new Map();
    const unassignedLicenses = [];
    for (const lic of licensesData.licenses) {
      if (!lic.device_id) { unassignedLicenses.push(lic); continue; }
      const current = licenseByDevice.get(lic.device_id);
      if (!current || licenseRank(lic) > licenseRank(current)) licenseByDevice.set(lic.device_id, lic);
    }

    const deviceEntries = devicesData.devices.map((d) => ({
      device: d,
      license: licenseByDevice.get(d.device_id) || null,
    }));
    const unassignedEntries = unassignedLicenses.map((lic) => ({ device: null, license: lic }));

    // register_device (nexapos_platform) is a separate, manual "Device
    // Sync" settings action - it is NOT called automatically when a
    // license activates. A solo-device shop that never opens that
    // screen has activated a real license with a real device_id, but
    // has no row in clients at all - so its license would otherwise
    // vanish here just like the no-device_id case above, just for a
    // different reason. Surfaced as its own row with a synthetic device
    // object carrying only the device_id we actually know.
    const matchedDeviceIds = new Set(devicesData.devices.map((d) => d.device_id));
    const orphanedEntries = [...licenseByDevice.entries()]
      .filter(([deviceId]) => !matchedDeviceIds.has(deviceId))
      .map(([deviceId, lic]) => ({ device: { device_id: deviceId, unregistered: true }, license: lic }));

    deviceRows = [...unassignedEntries, ...orphanedEntries, ...deviceEntries]
      .sort((a, b) => entryCreatedAt(b) - entryCreatedAt(a));
    saveAdminSecret(secret);

    const loadedAt = new Date();
    document.getElementById('lastLoaded').textContent = 'Loaded ' + deviceRows.length + ' device(s) at ' + loadedAt.toLocaleTimeString();
    document.getElementById('refreshStatus').textContent = 'Auto-refreshing every 5s - last updated ' + loadedAt.toLocaleTimeString();
    renderRows();
    // Started once and left running - re-fetching every loadDashboard()
    // call used to also tear down and rebuild tickInterval each time,
    // which just meant needless 1s-interval churn on every 5s auto-tick.
    if (!tickInterval) tickInterval = setInterval(renderRows, 1000);
    if (!autoRefreshInterval) autoRefreshInterval = setInterval(loadDashboard, AUTO_REFRESH_MS);
  } catch (e) {
    showMsg('loadMsg', String(e.message || e));
  } finally {
    btn.disabled = false;
  }
}

let supportRefreshInFlight = false;
async function refreshSupportTickets() {
  if (supportRefreshInFlight) return;
  const secret = adminSecret();
  if (!secret) return;
  supportRefreshInFlight = true;
  try {
    const platformUrl = document.getElementById('platformUrl').value.trim();
    const response = await fetch(platformUrl + '?action=admin_list_support_tickets', {
      headers: { 'X-Admin-Secret': secret },
    });
    const data = await response.json();
    if (!data.success || !Array.isArray(data.tickets)) {
      throw new Error(data.message || 'Could not load support tickets.');
    }
    supportTickets = data.tickets;
    renderSupportTickets();
  } catch (error) {
    document.getElementById('supportCount').textContent = '(unavailable)';
    renderEmptyRow('supportRows', 5, 'Support tickets could not load. Refresh to retry.');
  } finally {
    supportRefreshInFlight = false;
  }
}

// Not-revoked always outranks revoked regardless of recency (a fresh
// replacement code always wins over an old revoked one), and within the
// same revoked state, later activation wins. The 1e15 multiplier just
// needs to dwarf any real epoch-millis value (~1.8e12 today) so the
// revoked/not-revoked term always decides the comparison first.
function licenseRank(lic) {
  const activatedMs = lic.activated_at ? new Date(lic.activated_at.replace(' ', 'T') + 'Z').getTime() : 0;
  return (Number(lic.revoked) === 1 ? 0 : 1) * 1e15 + activatedMs;
}

function entryCreatedAt({ device, license }) {
  const raw = device?.created_at || license?.activated_at || license?.issued_at;
  if (!raw) return 0;
  const timestamp = new Date(raw.replace(' ', 'T') + 'Z').getTime();
  return Number.isFinite(timestamp) ? timestamp : 0;
}

function parseServerDate(raw) {
  if (!raw) return null;
  const ms = new Date(raw.replace(' ', 'T') + 'Z').getTime();
  return Number.isFinite(ms) ? ms : null;
}

function formatCountdown(validUntilRaw) {
  if (!validUntilRaw) return 'Never expires';
  const target = parseServerDate(validUntilRaw);
  const remaining = target - Date.now();
  if (remaining <= 0) return 'Expired';
  return formatDuration(remaining) + ' left';
}

// The one place "how long until this code/license expires" is worked out,
// used by every table that has a Code status column - a revoked code has
// no meaningful expiry (it's just over), and an unused code counts down to
// when the CODE itself expires rather than a license's valid_until, which
// doesn't exist until the code is actually activated.
function codeExpiryCell(license) {
  if (!license || license.status === 'revoked') return '-';
  const source = license.status === 'unused' ? license.expires_at : license.valid_until;
  return formatCountdown(source);
}

// Shared by "online for"/"last seen" (elapsed since a past timestamp)
// and the expiry countdown (remaining until a future one) - same units,
// just fed a different sign of gap.
function formatDuration(ms) {
  const d = Math.floor(ms / 86400000);
  const h = Math.floor((ms % 86400000) / 3600000);
  const m = Math.floor((ms % 3600000) / 60000);
  const s = Math.floor((ms % 60000) / 1000);
  if (d > 0) return `${d}d ${h}h`;
  if (h > 0) return `${h}h ${m}m`;
  if (m > 0) return `${m}m ${s}s`;
  return `${s}s`;
}

// Elapsed time since last_seen_at, for an offline device in the Joined
// devices table - "last seen" is the only thing that means anything once
// a device has dropped out of the online window. Null only when a device
// registered but has never made a second authenticated call yet (the one
// that would actually stamp last_seen_at).
function formatLastSeen(device) {
  const lastSeen = parseServerDate(device.last_seen_at);
  if (lastSeen === null) return 'Never';
  return formatDuration(Math.max(0, Date.now() - lastSeen)) + ' ago';
}

function formatObservedOnline(device) {
  const started = onlineObservedAt.get(device.device_id);
  return started == null ? '0s' : formatDuration(Math.max(0, Date.now() - started));
}

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function matchesSearch(device, license, query) {
  if (!query) return true;
  const haystack = [
    device?.device_label, device?.device_id, device?.business_name, license?.code,
  ].filter(Boolean).join(' ').toLowerCase();
  return haystack.includes(query);
}

// A real, still-active device only - excludes the "not yet activated"
// (no device at all) and "not registered for sync" (no clients row)
// placeholder rows, and excludes disabled ones too (handled as its own
// Removed section instead).
function isRealActiveDevice(device) {
  return !!device && device.unregistered !== true && device.status !== 'disabled';
}

function isNeverRegistered(device) {
  return !device || device.unregistered === true;
}

function isDisabledDevice(device) {
  return !!device && device.status === 'disabled';
}

function isDeviceOnline(device) {
  if (!isRealActiveDevice(device)) return false;
  const lastSeen = parseServerDate(device.last_seen_at);
  return lastSeen !== null && (Date.now() - lastSeen) < ONLINE_WINDOW_MS;
}

function codeCells(license) {
  const codeStatusMap = { used: 'Used', extended: 'Extended', revoked: 'Revoked', unused: 'Unused' };
  // Buttons carry their value in a data- attribute (read back with .dataset, never run as code) and are handled by
  // the one click listener at the end of this file. A value written into onclick="..." is NOT safe even when
  // escaped: the browser turns &#39; back into ' before it runs the handler, so a device whose name or ID held
  // script (anyone can register one) ran it here - and this page holds the admin secret.
  const codeCell = license
    ? `${escapeHtml(license.code)}<button class="copy-btn" type="button" data-copy-code="${escapeHtml(license.code)}">Copy</button>`
    : '-';
  const statusCell = license
    ? `<span class="pill ${escapeHtml(license.status)}">${escapeHtml(codeStatusMap[license.status] || license.status)}</span>`
    : '-';
  return { codeCell, statusCell };
}

// Device ID as plain mono text plus a small icon button to copy it -
// every table that shows a real device_id gets the same treatment, since
// this value is typed nowhere by a human but is exactly what support
// needs quoted back when troubleshooting a specific device.
function deviceIdCell(deviceId) {
  if (!deviceId) return '-';
  return `<span class="id-cell"><span>${escapeHtml(deviceId)}</span>` +
    `<button class="icon-btn" type="button" title="Copy device ID" aria-label="Copy device ID" ` +
    `data-copy-device-id="${escapeHtml(deviceId)}">${COPY_ICON_SVG}</button></span>`;
}

function deleteButton(device) {
  // No real clients.id exists to revoke for an unregistered device - it
  // was never registered on nexapos_platform in the first place.
  if (!device || isNeverRegistered(device) || isDisabledDevice(device)) return '';
  return `<button class="delete-btn" type="button" data-delete-device="${Number(device.id)}" data-label="${escapeHtml(device.device_label || 'this device')}">Delete</button>`;
}

function labelOf(device) {
  return escapeHtml(device.device_label || 'Unnamed device') +
    (Number(device.is_owner) === 1 ? ' <span class="owner-tag">OWNER</span>' : '');
}

function shopOf(device) {
  return escapeHtml(device.business_name || ('Shop #' + device.shop_id));
}

function renderEmptyRow(tbodyId, colspan, message) {
  document.getElementById(tbodyId).innerHTML =
    `<tr class="empty-row"><td colspan="${colspan}">${message}</td></tr>`;
}

// Groups the (already search-filtered) rows that have a real shop_id -
// unregistered/orphan placeholder rows carry no shop at all, so they're
// excluded rather than lumped into some fake "Shop #undefined" group.
// A shop counts as revoked once every device it has is disabled; a
// shop with zero devices left (all deleted/never registered under it)
// shows as revoked too, since there's nothing left able to authenticate.
function groupByShop(visible) {
  const shops = new Map();
  for (const { device } of visible) {
    if (!device || isNeverRegistered(device) || device.shop_id == null) continue;
    const key = device.shop_id;
    if (!shops.has(key)) {
      shops.set(key, { shopId: key, businessName: device.business_name, devices: [] });
    }
    shops.get(key).devices.push(device);
  }
  return [...shops.values()].map((shop) => {
    const activeCount = shop.devices.filter((d) => !isDisabledDevice(d)).length;
    const joinedAtMs = Math.min(...shop.devices.map((d) => parseServerDate(d.created_at) ?? Infinity));
    return {
      ...shop,
      totalCount: shop.devices.length,
      activeCount,
      revoked: activeCount === 0,
      joinedAtMs: Number.isFinite(joinedAtMs) ? joinedAtMs : null,
    };
  }).sort((a, b) => (b.joinedAtMs ?? 0) - (a.joinedAtMs ?? 0));
}

function renderShopRows(visible) {
  const shops = groupByShop(visible);
  document.getElementById('shopCount').textContent = `(${shops.length})`;
  if (shops.length === 0) {
    renderEmptyRow('shopRows', 5, 'No joined shops yet.');
    return;
  }
  document.getElementById('shopRows').innerHTML = shops.map((shop) => {
    const joined = shop.joinedAtMs ? new Date(shop.joinedAtMs).toLocaleDateString() : 'Unknown';
    const statusCell = shop.revoked
      ? '<span class="pill revoked">Revoked</span>'
      : '<span class="pill online">Active</span>';
    const revokeBtn = shop.revoked
      ? ''
      : `<button class="delete-btn" type="button" data-revoke-shop="${Number(shop.shopId)}" data-name="${escapeHtml(shop.businessName || ('Shop #' + shop.shopId))}">Revoke</button>`;
    return `<tr>
      <td>${escapeHtml(shop.businessName || ('Shop #' + shop.shopId))}</td>
      <td>${Number(shop.activeCount)} / ${Number(shop.totalCount)}</td>
      <td>${joined}</td>
      <td>${statusCell}</td>
      <td>${revokeBtn}</td>
    </tr>`;
  }).join('');
}

const SUPPORT_STATUS_LABEL = { open: 'Open', pending: 'Pending', closed: 'Closed' };

// Who a ticket is from: a shop (opened in the app) or a visitor of the public website.
function ticketOrigin(ticket) {
  if (ticket.channel === 'website') {
    return 'Website visitor' + (ticket.visitor_name ? ' · ' + ticket.visitor_name : '');
  }
  return ticket.business_name || ('Shop #' + ticket.shop_id);
}

// Where a copy of every reply goes. Every ticket carries an email address; an old one may not.
function ticketContactLine(ticket) {
  const email = ticket.customer_email;
  const emailPart = email ? 'every reply is also emailed to ' + email : 'no email on this ticket, so replies are not emailed';
  if (ticket.channel === 'website') return 'Website chat · ' + emailPart;
  const opened = ticket.opened_by_device_id
    ? 'Opened from ' + (ticket.opened_by_device_label || 'a device') + ' · device ID ' + ticket.opened_by_device_id + ' · '
    : '';
  return opened + emailPart;
}

function renderSupportTickets() {
  document.getElementById('supportCount').textContent = `(${supportTickets.length})`;
  if (supportTickets.length === 0) {
    renderEmptyRow('supportRows', 5, 'No support tickets yet.');
    return;
  }
  document.getElementById('supportRows').innerHTML = supportTickets.map((ticket) => {
    const updated = parseServerDate(ticket.updated_at);
    const updatedText = updated === null ? '-' : formatDuration(Math.max(0, Date.now() - updated)) + ' ago';
    return `<tr class="support-row" data-open-ticket="${Number(ticket.id)}">
      <td>${escapeHtml(ticketOrigin(ticket))}</td>
      <td class="subject">${escapeHtml(ticket.subject)}${Number(ticket.attachment_count) > 0 ? ` <small>· ${Number(ticket.attachment_count)} photo${Number(ticket.attachment_count) === 1 ? '' : 's'}</small>` : ''}</td>
      <td><span class="pill ${escapeHtml(ticket.status)}">${escapeHtml(SUPPORT_STATUS_LABEL[ticket.status] || ticket.status)}</span></td>
      <td>${Number(ticket.message_count)}</td>
      <td>${updatedText}</td>
    </tr>`;
  }).join('');
  // The open dialog, if any, shows live data - a background auto-refresh
  // must not go stale while the admin is mid-read of a ticket, but also
  // must not fight a reply that's still being typed by re-rendering the
  // reply textarea out from under them.
  if (openTicketId !== null) refreshOpenTicketThread();
}

let ticketPhotos = [];
let ticketThreadSignature = null;
function clearTicketPhotos() {
  ticketPhotos = [];
  document.getElementById('ticketPhotoInput').value = '';
  document.getElementById('ticketPhotoDrafts').replaceChildren();
}
async function addTicketPhotos(files) {
  const list = Array.from(files);
  if (ticketPhotos.length + list.length > 4 || list.some(f => f.size > 2097152 || !['image/png','image/jpeg','image/webp'].includes(f.type)) ||
      ticketPhotos.reduce((n,p) => n+p.size, 0) + list.reduce((n,f) => n+f.size, 0) > 4194304) {
    showMsg('ticketReplyMsg', 'Use up to 4 PNG/JPEG/WebP photos, 2 MB each and 4 MB total.'); return;
  }
  const ticketId = openTicketId;
  const photos = await Promise.all(list.map(file => new Promise((resolve, reject) => {
      const reader = new FileReader(); reader.onload = () => resolve(String(reader.result));
      reader.onerror = reject; reader.readAsDataURL(file);
    }).then(data => ({data: data.split(',')[1], size: file.size, preview: data}))));
  if (ticketId !== openTicketId) return;
  ticketPhotos.push(...photos);
  renderTicketPhotoDrafts();
}
// The photos waiting to be sent: click one to see it full size, the round X takes it off.
function renderTicketPhotoDrafts() {
  const container = document.getElementById('ticketPhotoDrafts'); container.replaceChildren();
  ticketPhotos.forEach((photo, index) => {
    const wrap = document.createElement('div'); wrap.className = 'draft-photo';
    const image = document.createElement('img'); image.src = photo.preview; image.alt = 'Attached photo'; image.title = 'Click to preview';
    image.addEventListener('click', () => openPhotoLightbox(photo.preview));
    const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'draft-remove';
    remove.title = 'Remove photo'; remove.setAttribute('aria-label', 'Remove photo'); remove.textContent = '✕';
    remove.addEventListener('click', () => { ticketPhotos.splice(index, 1); renderTicketPhotoDrafts(); });
    wrap.append(image, remove); container.append(wrap);
  });
}
document.getElementById('ticketAttachBtn').addEventListener('click', () => document.getElementById('ticketPhotoInput').click());
document.getElementById('ticketPhotoInput').addEventListener('change', e => {
  addTicketPhotos(e.target.files).catch(() => showMsg('ticketReplyMsg','Could not read the photo.')).finally(() => { e.target.value = ''; });
});
document.getElementById('ticketReplyText').addEventListener('paste', e => {
  const photos = Array.from(e.clipboardData?.files || []).filter(file => file.type.startsWith('image/'));
  if (photos.length) { e.preventDefault(); addTicketPhotos(photos).catch(() => showMsg('ticketReplyMsg','Could not paste the photo.')); }
});

// --- Photos in the conversation: loaded once each (kept for the life of the page), shown as
// thumbnails, a click opens them full size.
const supportPhotoUrls = new Map();
function supportPhotoUrl(id) {
  if (!supportPhotoUrls.has(id)) {
    const loading = (async () => {
      const url = new URL(document.getElementById('platformUrl').value.trim());
      url.search = new URLSearchParams({action:'admin_support_attachment',id:String(id)});
      const response = await fetch(url, {headers: {'X-Admin-Secret': adminSecret()}});
      if (!response.ok || !(response.headers.get('Content-Type') || '').startsWith('image/')) throw new Error('Photo unavailable');
      return URL.createObjectURL(await response.blob());
    })();
    loading.catch(() => supportPhotoUrls.delete(id));
    supportPhotoUrls.set(id, loading);
  }
  return supportPhotoUrls.get(id);
}
async function showSupportPhoto(slot) {
  slot.textContent = 'Loading photo…';
  try {
    const src = await supportPhotoUrl(Number(slot.dataset.supportPhoto));
    const image = document.createElement('img'); image.className = 'ticket-photo'; image.alt = 'Support photo'; image.title = 'Click to preview';
    image.addEventListener('click', () => openPhotoLightbox(src));
    // A photo arriving makes the conversation taller: stay on the newest message if that is where we were.
    image.addEventListener('load', () => { if (threadPinned) { const thread = document.getElementById('ticketThread'); thread.scrollTop = thread.scrollHeight; } });
    image.src = src;
    slot.replaceWith(image);
  } catch (_) {
    slot.textContent = '';
    const retry = document.createElement('button'); retry.type = 'button'; retry.textContent = 'Retry photo';
    retry.addEventListener('click', () => showSupportPhoto(slot));
    slot.append(retry);
  }
}
function openPhotoLightbox(src) {
  const dialog = document.getElementById('photoLightbox');
  const image = document.getElementById('lightboxImage');
  image.classList.remove('zoomed'); image.src = src;
  document.getElementById('lightboxDownload').href = src;
  if (typeof dialog.showModal === 'function' && !dialog.open) dialog.showModal();
}
document.getElementById('lightboxImage').addEventListener('click', e => e.currentTarget.classList.toggle('zoomed'));
document.getElementById('lightboxClose').addEventListener('click', () => document.getElementById('photoLightbox').close());
document.getElementById('photoLightbox').addEventListener('click', e => { if (e.target === e.currentTarget) e.currentTarget.close(); });

// --- Replying to a message, and reacting to one (double-click a message, like iMessage).
const REACTION_CHOICES = ['❤️', '👍', '👎', '😂', '‼️', '❓'];
const stripVariation = (emoji) => String(emoji).replace(/️/g, '');
let replyTarget = null;
let ticketMessages = [];
let messageMenu = null;

function messageWho(sender) { return sender === 'support' ? 'You' : 'Customer'; }
function supportDeliveryLabel(message) { return message.read_at ? 'Read' : 'Delivered'; }
function messageSnippet(m) { return (m.body || '').replace(/\s+/g, ' ').trim().slice(0, 120) || 'Photo'; }

function setReplyTarget(m) {
  replyTarget = m ? { id: m.id, sender: m.sender, snippet: messageSnippet(m) } : null;
  const bar = document.getElementById('replyPreview');
  bar.hidden = !replyTarget;
  if (replyTarget) {
    document.getElementById('replyPreviewWho').textContent = 'Replying to ' + messageWho(replyTarget.sender);
    document.getElementById('replyPreviewText').textContent = replyTarget.snippet;
    document.getElementById('ticketReplyText').focus();
  }
}
document.getElementById('replyPreviewClose').addEventListener('click', () => setReplyTarget(null));

function closeMessageMenu() {
  if (messageMenu) { messageMenu.remove(); messageMenu = null; }
}
function openMessageMenu(m, bubble) {
  closeMessageMenu();
  const dialog = document.getElementById('ticketDialog');
  const mine = (m.reactions || []).find(r => r.sender === 'support');
  const menu = document.createElement('div'); menu.className = 'msg-menu'; menu.setAttribute('role', 'menu');
  const row = document.createElement('div'); row.className = 'msg-menu-emojis';
  REACTION_CHOICES.forEach(emoji => {
    const pick = document.createElement('button'); pick.type = 'button'; pick.textContent = emoji; pick.title = 'React';
    if (mine && stripVariation(mine.emoji) === stripVariation(emoji)) pick.classList.add('chosen');
    pick.addEventListener('click', () => {
      closeMessageMenu();
      reactToMessage(m.id, mine && stripVariation(mine.emoji) === stripVariation(emoji) ? '' : emoji);
    });
    row.append(pick);
  });
  const reply = document.createElement('button'); reply.type = 'button'; reply.textContent = '↩  Reply';
  reply.addEventListener('click', () => { closeMessageMenu(); setReplyTarget(m); });
  const copy = document.createElement('button'); copy.type = 'button'; copy.textContent = '⧉  Copy text'; copy.disabled = !m.body;
  copy.addEventListener('click', () => { closeMessageMenu(); navigator.clipboard?.writeText(m.body).catch(() => {}); });
  menu.append(row, reply, copy);
  dialog.append(menu);
  // Above the bubble, lined up with the side it sits on; below it when there is no room above.
  const d = dialog.getBoundingClientRect(), b = bubble.getBoundingClientRect(), width = menu.offsetWidth, height = menu.offsetHeight;
  let top = b.top - d.top + dialog.scrollTop - height - 8;
  if (top < 4) top = b.bottom - d.top + dialog.scrollTop + 8;
  let left = (m.sender === 'support' ? b.right - width : b.left) - d.left + dialog.scrollLeft;
  left = Math.max(6, Math.min(left, d.width - width - 6));
  menu.style.top = top + 'px'; menu.style.left = left + 'px';
  messageMenu = menu;
}
document.getElementById('ticketDialog').addEventListener('mousedown', e => { if (messageMenu && !messageMenu.contains(e.target)) closeMessageMenu(); });
document.getElementById('ticketDialog').addEventListener('cancel', e => { if (messageMenu) { e.preventDefault(); closeMessageMenu(); } });

async function reactToMessage(messageId, emoji) {
  if (openTicketId === null) return;
  try {
    const platformUrl = document.getElementById('platformUrl').value.trim();
    const res = await fetch(platformUrl + '?action=admin_support_react', {
      method: 'POST',
      headers: { 'X-Admin-Secret': adminSecret(), 'Content-Type': 'application/json' },
      body: JSON.stringify({ ticket_id: openTicketId, message_id: messageId, emoji }),
    });
    const data = await res.json();
    if (!data.success) { showMsg('ticketReplyMsg', data.message || 'Could not save the reaction.'); return; }
    ticketThreadSignature = null;
    await refreshOpenTicketThread();
  } catch (e) {
    showMsg('ticketReplyMsg', 'Could not reach the server: ' + e);
  }
}

// "Pinned" = the reader is at the newest message, so a new message or a photo that finishes
// loading keeps them there. Only the reader's own scrolling changes it (not the page's layout
// shifting under them), hence the check for a recent wheel/touch/key/mouse press.
let threadPinned = true;
let lastReaderScroll = 0;
const threadEl = document.getElementById('ticketThread');
['wheel', 'touchmove', 'keydown', 'pointerdown'].forEach(name => threadEl.addEventListener(name, () => { lastReaderScroll = Date.now(); }, { passive: true }));
threadEl.addEventListener('scroll', () => {
  if (Date.now() - lastReaderScroll < 400) threadPinned = threadEl.scrollHeight - threadEl.scrollTop - threadEl.clientHeight < 40;
});
function renderTicketMessages(messages, firstRender, noteHtml = '') {
  ticketMessages = messages;
  closeMessageMenu();
  const thread = document.getElementById('ticketThread');
  const atBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 40;
  thread.innerHTML = noteHtml + messages.map((m) => {
    const mine = m.sender === 'support';
    const device = !mine && m.device_label ? ` · ${escapeHtml(m.device_label)}` : '';
    const deviceTitle = !mine && m.device_id ? ` title="Device ID: ${escapeHtml(m.device_id)}"` : '';
    const quote = m.reply_to
      ? `<div class="ticket-quote" data-quote="${Number(m.reply_to.id)}"><b>${messageWho(m.reply_to.sender)}</b><span class="ticket-quote-text">${escapeHtml(m.reply_to.snippet || 'Photo')}</span></div>`
      : '';
    const photos = (m.attachments || []).map(a => `<div class="ticket-photo-slot" data-support-photo="${Number(a.id)}">Loading photo…</div>`).join('');
    const reactions = (m.reactions || []).length
      ? `<div class="reaction-row">${m.reactions.map(r => {
          const own = r.sender === 'support';
          return `<button type="button" class="reaction-chip${own ? ' mine' : ''}" data-react="${Number(m.id)}" data-emoji="${escapeHtml(r.emoji)}" data-own="${own ? 1 : 0}" title="${own ? 'Your reaction - click to remove it' : 'The customer\'s reaction - click to react the same'}">${escapeHtml(r.emoji)}</button>`;
        }).join('')}</div>`
      : '';
    const delivery = mine
      ? `<div class="ticket-delivery" title="${m.read_at ? 'Opened by the shop at ' + escapeHtml(m.read_at) : 'Accepted by the support server; not yet opened by the shop'}">${supportDeliveryLabel(m)}</div>`
      : '';
    return `<div class="chat-row ${mine ? 'support' : 'customer'}" data-row="${Number(m.id)}"><div class="ticket-msg ${mine ? 'support' : 'customer'}" data-msg="${Number(m.id)}"${deviceTitle}>${quote}<div class="ticket-msg-meta">${messageWho(m.sender)}${device} · ${escapeHtml(m.created_at)}</div>${m.body ? `<div class="ticket-body">${escapeHtml(m.body)}</div>` : ''}${photos}</div>${reactions}${delivery}</div>`;
  }).join('') || '<p class="section-sub">No messages yet.</p>';

  thread.querySelectorAll('.ticket-msg[data-msg]').forEach(bubble => bubble.addEventListener('dblclick', (e) => {
    window.getSelection()?.removeAllRanges();
    e.preventDefault();
    const m = ticketMessages.find(x => x.id === Number(bubble.dataset.msg));
    if (m) openMessageMenu(m, bubble);
  }));
  thread.querySelectorAll('[data-quote]').forEach(q => q.addEventListener('click', () => {
    const row = thread.querySelector(`[data-row="${Number(q.dataset.quote)}"]`);
    if (!row) return;
    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    row.classList.add('flash'); setTimeout(() => row.classList.remove('flash'), 1200);
  }));
  thread.querySelectorAll('[data-react]').forEach(chip => chip.addEventListener('click', () => {
    reactToMessage(Number(chip.dataset.react), chip.dataset.own === '1' ? '' : chip.dataset.emoji);
  }));
  thread.querySelectorAll('[data-support-photo]').forEach(slot => showSupportPhoto(slot));
  threadPinned = firstRender || atBottom;
  if (threadPinned) thread.scrollTop = thread.scrollHeight;
}

async function openTicketThread(ticketId) {
  openTicketId = ticketId;
  ticketThreadSignature = null;
  const dialog = document.getElementById('ticketDialog');
  document.getElementById('ticketReplyText').value = '';
  clearTicketPhotos();
  setReplyTarget(null);
  closeMessageMenu();
  document.getElementById('ticketReplyMsg').className = 'msg err';
  if (typeof dialog.showModal === 'function') dialog.showModal();
  await refreshOpenTicketThread();
}

async function refreshOpenTicketThread() {
  if (openTicketId === null) return;
  const ticketId = openTicketId;
  const secret = adminSecret();
  const platformUrl = document.getElementById('platformUrl').value.trim();
  try {
    const res = await fetch(platformUrl + '?action=admin_support_thread&ticket_id=' + ticketId, {
      headers: { 'X-Admin-Secret': secret },
    });
    const data = await res.json();
    if (openTicketId !== ticketId) return; // closed/switched while this was in flight
    if (!data.success) {
      document.getElementById('ticketThread').innerHTML = `<p class="section-sub">${escapeHtml(data.message || 'Could not load this ticket.')}</p>`;
      return;
    }
    const { ticket, messages } = data;
    const signature = JSON.stringify([ticket, messages]);
    if (signature === ticketThreadSignature) return;
    const firstRender = ticketThreadSignature === null;
    ticketThreadSignature = signature;
    document.getElementById('ticketDialogShop').textContent =
      ticketOrigin(ticket) + ' · ' + (SUPPORT_STATUS_LABEL[ticket.status] || ticket.status);
    document.getElementById('ticketDialogSubject').textContent = ticket.subject;
    document.getElementById('ticketDialogDevice').textContent = ticketContactLine(ticket);
    // The ticket list says it has photos but the thread came back without any: the dashboard is
    // pointed at a sync API that is too old to send them. Say so instead of showing nothing.
    const returnedPhotoCount = messages.reduce((count, message) => count + (message.attachments || []).length, 0);
    const listedPhotoCount = Number(supportTickets.find(row => row.id === ticketId)?.attachment_count || 0);
    renderTicketMessages(messages, firstRender, listedPhotoCount > 0 && returnedPhotoCount === 0
      ? '<p class="section-sub">This ticket has photos, but the support server did not return their details. Refresh the page; if they are still missing, check that the dashboard points to the current sync API.</p>'
      : '');
    document.getElementById('ticketResolveBtn').disabled = ticket.status === 'closed';
    document.getElementById('ticketResolveBtn').textContent = ticket.status === 'closed' ? 'Resolved' : 'Mark resolved';
    // Update the row in the background list too, without waiting for the
    // next 5s auto-refresh - otherwise the list looks stale right after
    // replying even though the thread the admin is looking at just changed.
    const row = supportTickets.find((t) => t.id === ticketId);
    if (row) { row.status = ticket.status; row.message_count = messages.length; }
  } catch (e) {
    document.getElementById('ticketThread').innerHTML = `<p class="section-sub">Could not reach the server: ${escapeHtml(String(e))}</p>`;
  }
}

function closeTicketDialog() {
  openTicketId = null;
  const dialog = document.getElementById('ticketDialog');
  if (typeof dialog.close === 'function') dialog.close();
  renderSupportTickets();
}

// The short "message sent" sound (/message-sent.mp3, at the top of this site), played once the server has
// the reply. A browser that refuses to play sound simply stays quiet.
const sentSound = new Audio('/message-sent.mp3');
sentSound.preload = 'auto';
function playSentSound() {
  try {
    sentSound.currentTime = 0;
    const playing = sentSound.play();
    if (playing && playing.catch) playing.catch(() => {});
  } catch (_) { /* no sound is better than a failed reply */ }
}

async function sendTicketReply() {
  if (openTicketId === null) return;
  const message = document.getElementById('ticketReplyText').value.trim();
  if (!message && !ticketPhotos.length) {
    showMsg('ticketReplyMsg', 'Type a reply or attach a photo.');
    return;
  }
  const btn = document.getElementById('ticketReplyBtn');
  btn.disabled = true;
  showMsg('ticketReplyMsg', '');
  try {
    const secret = adminSecret();
    const platformUrl = document.getElementById('platformUrl').value.trim();
    const res = await fetch(platformUrl + '?action=admin_support_reply', {
      method: 'POST',
      headers: { 'X-Admin-Secret': secret, 'Content-Type': 'application/json' },
      body: JSON.stringify({ ticket_id: openTicketId, message, reply_to_id: replyTarget ? replyTarget.id : undefined, attachments: ticketPhotos.map(photo => ({data: photo.data})) }),
    });
    const data = await res.json();
    if (!data.success) {
      showMsg('ticketReplyMsg', data.message || 'Could not send the reply.');
      return;
    }
    playSentSound();
    document.getElementById('ticketReplyText').value = '';
    clearTicketPhotos();
    setReplyTarget(null);
    ticketThreadSignature = null; // draw it as a first render, which also scrolls to the newest message
    await refreshOpenTicketThread();
    // The server mails every reply to the ticket's address; say so, or say that there is none.
    const sentTo = supportTickets.find((t) => t.id === openTicketId)?.customer_email;
    if (data.emailed === true) showOk('ticketReplyMsg', 'Reply sent - a copy was emailed to ' + (sentTo || 'the client') + '.');
    else if (data.emailed === false) showMsg('ticketReplyMsg', 'Reply sent in the chat, but the email copy was NOT sent' + (sentTo ? ' to ' + sentTo : ' (this ticket has no email address)') + '.');
  } catch (e) {
    showMsg('ticketReplyMsg', 'Could not reach the server: ' + e);
  } finally {
    btn.disabled = false;
  }
}

async function resolveTicket() {
  if (openTicketId === null) return;
  const secret = adminSecret();
  const platformUrl = document.getElementById('platformUrl').value.trim();
  try {
    const res = await fetch(platformUrl + '?action=admin_close_support_ticket', {
      method: 'POST',
      headers: { 'X-Admin-Secret': secret, 'Content-Type': 'application/json' },
      body: JSON.stringify({ ticket_id: openTicketId }),
    });
    const data = await res.json();
    if (!data.success) {
      showMsg('ticketReplyMsg', data.message || 'Could not resolve this ticket.');
      return;
    }
    await refreshOpenTicketThread();
  } catch (e) {
    showMsg('ticketReplyMsg', 'Could not reach the server: ' + e);
  }
}

function renderRows() {
  const query = document.getElementById('search').value.trim().toLowerCase();
  const visible = deviceRows.filter(({ device, license }) => matchesSearch(device, license, query));

  renderShopRows(visible);

  const online = visible.filter(({ device }) => isDeviceOnline(device));
  const offline = visible.filter(({ device }) => isRealActiveDevice(device) && !isDeviceOnline(device));
  // A heartbeat updates last_seen_at, but it must not restart the displayed
  // time for a device that has stayed online during this dashboard session.
  const onlineIds = new Set(online.map(({ device }) => device.device_id));
  for (const id of onlineIds) {
    if (!onlineObservedAt.has(id)) onlineObservedAt.set(id, Date.now());
  }
  for (const id of onlineObservedAt.keys()) {
    if (!onlineIds.has(id)) onlineObservedAt.delete(id);
  }
  const unregistered = visible.filter(({ device }) => isNeverRegistered(device));
  const disabled = visible.filter(({ device }) => isDisabledDevice(device));

  document.getElementById('onlineCount').textContent = `(${online.length})`;
  document.getElementById('offlineCount').textContent = `(${offline.length})`;
  document.getElementById('unregisteredCount').textContent = `(${unregistered.length})`;
  document.getElementById('disabledCount').textContent = `(${disabled.length})`;

  if (online.length === 0) {
    renderEmptyRow('onlineRows', 8, 'No online devices right now.');
  } else {
    document.getElementById('onlineRows').innerHTML = online.map(({ device, license }) => {
      const { codeCell, statusCell } = codeCells(license);
      return `<tr>
        <td>${labelOf(device)}</td>
        <td class="mono">${deviceIdCell(device.device_id)}</td>
        <td>${shopOf(device)}</td>
        <td>${formatObservedOnline(device)}</td>
        <td class="mono">${codeCell}</td>
        <td>${statusCell}</td>
        <td>${codeExpiryCell(license)}</td>
        <td>${deleteButton(device)}</td>
      </tr>`;
    }).join('');
  }

  if (offline.length === 0) {
    renderEmptyRow('offlineRows', 8, 'No offline devices.');
  } else {
    document.getElementById('offlineRows').innerHTML = offline.map(({ device, license }) => {
      const { codeCell, statusCell } = codeCells(license);
      return `<tr>
        <td>${labelOf(device)}</td>
        <td class="mono">${deviceIdCell(device.device_id)}</td>
        <td>${shopOf(device)}</td>
        <td>${formatLastSeen(device)}</td>
        <td class="mono">${codeCell}</td>
        <td>${statusCell}</td>
        <td>${codeExpiryCell(license)}</td>
        <td>${deleteButton(device)}</td>
      </tr>`;
    }).join('');
  }

  if (unregistered.length === 0) {
    renderEmptyRow('unregisteredRows', 6, 'No unregistered devices or unused codes.');
  } else {
    document.getElementById('unregisteredRows').innerHTML = unregistered.map(({ device, license }) => {
      const isOrphan = device?.unregistered === true;
      const label = isOrphan
        ? '<span style="color:#aaa">Activated - not registered for sync</span>'
        : '<span style="color:#aaa">Not yet activated</span>';
      const statusPill = isOrphan
        ? '<span class="pill nocode">Not registered for sync</span>'
        : '<span class="pill nocode">Not yet activated</span>';
      const { codeCell, statusCell } = codeCells(license);
      return `<tr>
        <td>${label}</td>
        <td class="mono">${device ? deviceIdCell(device.device_id) : '-'}</td>
        <td>${statusPill}</td>
        <td class="mono">${codeCell}</td>
        <td>${statusCell}</td>
        <td>${codeExpiryCell(license)}</td>
      </tr>`;
    }).join('');
  }

  if (disabled.length === 0) {
    renderEmptyRow('disabledRows', 6, 'No removed devices.');
  } else {
    document.getElementById('disabledRows').innerHTML = disabled.map(({ device, license }) => {
      const { codeCell, statusCell } = codeCells(license);
      return `<tr>
        <td>${labelOf(device)}</td>
        <td class="mono">${deviceIdCell(device.device_id)}</td>
        <td>${shopOf(device)}</td>
        <td class="mono">${codeCell}</td>
        <td>${statusCell}</td>
        <td>${codeExpiryCell(license)}</td>
      </tr>`;
    }).join('');
  }
}

async function copyCode(code, btn) {
  try {
    await navigator.clipboard.writeText(code);
    const original = btn.textContent;
    btn.textContent = 'Copied!';
    btn.disabled = true;
    setTimeout(() => { btn.textContent = original; btn.disabled = false; }, 1200);
  } catch (e) {
    alert('Could not copy: ' + e);
  }
}

async function copyDeviceId(deviceId, btn) {
  try {
    await navigator.clipboard.writeText(deviceId);
    btn.innerHTML = CHECK_ICON_SVG;
    btn.classList.add('copied');
    btn.disabled = true;
    setTimeout(() => {
      btn.innerHTML = COPY_ICON_SVG;
      btn.classList.remove('copied');
      btn.disabled = false;
    }, 1200);
  } catch (e) {
    alert('Could not copy: ' + e);
  }
}

// "Delete" here means the same thing revoke_device already means
// elsewhere in this app: status -> 'disabled', not a real SQL DELETE
// (see admin_revoke_device's own comment in nexapos_platform for why -
// clients.id is FK'd from transactions, and a hard delete would strand
// a shop with no owner if it happened to be the founding device). The
// device stops being able to authenticate at all from this point on -
// Auth::requireClient already enforces 'disabled' everywhere.
async function deleteDevice(clientId, label) {
  if (!confirm(`Delete "${label}"? This device will immediately lose all access and cannot authenticate again. This cannot be undone from here.`)) {
    return;
  }
  const secret = adminSecret();
  const platformUrl = document.getElementById('platformUrl').value.trim();
  try {
    const res = await fetch(platformUrl + '?action=admin_revoke_device', {
      method: 'POST',
      headers: { 'X-Admin-Secret': secret, 'Content-Type': 'application/json' },
      body: JSON.stringify({ client_id: clientId }),
    });
    const data = await res.json();
    if (!data.success) {
      alert(data.message || 'Could not delete that device.');
      return;
    }
    const row = deviceRows.find(({ device }) => device.id === clientId);
    if (row) row.device.status = 'disabled';
    renderRows();
  } catch (e) {
    alert('Could not reach the server: ' + e);
  }
}

// Shop-wide version of deleteDevice: disables every device the shop
// currently has in one call (admin_revoke_shop), then reflects that
// locally the same way - flips each affected device's status rather
// than re-fetching, so the table updates instantly.
async function revokeShop(shopId, name) {
  if (!confirm(`Revoke "${name}"? Every device this shop has will immediately lose all access and cannot authenticate again. This cannot be undone from here.`)) {
    return;
  }
  const secret = adminSecret();
  const platformUrl = document.getElementById('platformUrl').value.trim();
  try {
    const res = await fetch(platformUrl + '?action=admin_revoke_shop', {
      method: 'POST',
      headers: { 'X-Admin-Secret': secret, 'Content-Type': 'application/json' },
      body: JSON.stringify({ shop_id: shopId }),
    });
    const data = await res.json();
    if (!data.success) {
      alert(data.message || 'Could not revoke that shop.');
      return;
    }
    for (const { device } of deviceRows) {
      if (device && device.shop_id === shopId) device.status = 'disabled';
    }
    renderRows();
  } catch (e) {
    alert('Could not reach the server: ' + e);
  }
}

// A remembered, still-fresh secret loads the table immediately - no
// reason to make the admin click "Load devices" again just because
// they refreshed the page.
// Escape (or any other way the dialog closes besides the explicit X
// button) must still clear openTicketId - otherwise the next background
// refresh keeps trying to update a dialog nobody is looking at, and the
// support count/list behind it stays stuck mid-read.
document.getElementById('ticketDialog').addEventListener('close', () => {
  closeMessageMenu();
  openTicketId = null;
  renderSupportTickets();
});

if (restoreAdminSecret()) loadDashboard();

// Buttons and fields are wired here, not with onclick="..." in the page, so the page can run under a
// Content-Security-Policy that refuses inline script (see public/.htaccess).
(function wireControls() {
  function on(id, type, handler) { document.getElementById(id).addEventListener(type, handler); }
  on('loadBtn', 'click', function (event) { loadDashboard(); });
  on('search', 'input', function (event) { renderRows(); });
  on('refreshBtn', 'click', function (event) { loadDashboard(); });
  on('ticketDialogClose', 'click', function (event) { closeTicketDialog(); });
  on('ticketResolveBtn', 'click', function (event) { resolveTicket(); });
  on('ticketReplyBtn', 'click', function (event) { sendTicketReply(); });

  // The buttons the tables are drawn with (see codeCells, deviceIdCell, deleteButton, renderShops and
  // renderSupportTickets). Their values come back from data- attributes exactly as the server sent them.
  document.addEventListener('click', function (event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    const copyCodeBtn = target.closest('[data-copy-code]');
    if (copyCodeBtn) { copyCode(copyCodeBtn.dataset.copyCode, copyCodeBtn); return; }
    const copyIdBtn = target.closest('[data-copy-device-id]');
    if (copyIdBtn) { copyDeviceId(copyIdBtn.dataset.copyDeviceId, copyIdBtn); return; }
    const deleteBtn = target.closest('[data-delete-device]');
    if (deleteBtn) { deleteDevice(Number(deleteBtn.dataset.deleteDevice), deleteBtn.dataset.label); return; }
    const revokeBtn = target.closest('[data-revoke-shop]');
    if (revokeBtn) { revokeShop(Number(revokeBtn.dataset.revokeShop), revokeBtn.dataset.name); return; }
    const ticketRow = target.closest('[data-open-ticket]');
    if (ticketRow) { openTicketThread(Number(ticketRow.dataset.openTicket)); }
  });
})();
