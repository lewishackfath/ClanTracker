function qs(id) { return document.getElementById(id); }

const API = {
  clans: "api/search/clans.php",
  players: "api/search/players.php",
  clanOverview: "api/clan.php",
  player: "api/player.php",
  refreshPlayerXp: "api/refresh_player_xp.php",
};

/* ---------------- XP refresh helper ---------------- */

function parseUtcToMs(utc) {
  const s = String(utc || "").trim();
  if (!s) return null;
  // expects "YYYY-MM-DD HH:mm:ss" (UTC)
  const iso = s.includes("T") ? s : s.replace(" ", "T");
  const d = new Date(`${iso}Z`);
  const ms = d.getTime();
  return Number.isFinite(ms) ? ms : null;
}

function utcAgeSeconds(utc) {
  const ms = parseUtcToMs(utc);
  if (ms === null) return null;
  return Math.floor((Date.now() - ms) / 1000);
}

const xpRefreshAttempted = new Set();

function getParams() {
  const p = new URLSearchParams(window.location.search);
  return {
    clan: (p.get("clan") || "").trim(),
    player: (p.get("player") || "").trim(),
  };
}

function setQuery(params) {
  const url = new URL(window.location.href);
  url.searchParams.delete("clan");
  url.searchParams.delete("player");
  for (const [k, v] of Object.entries(params)) {
    if (v && String(v).trim()) url.searchParams.set(k, String(v).trim());
  }
  window.history.pushState({}, "", url);
  render();
}

function clearQuery() {
  const url = new URL(window.location.href);
  url.searchParams.delete("clan");
  url.searchParams.delete("player");
  window.history.pushState({}, "", url);
  render();
}

function show(el, yes) { el.classList.toggle("hidden", !yes); }
function normalise(v) { return String(v || "").trim(); }

function debounce(fn, delay = 250) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), delay);
  };
}

async function fetchJson(url) {
  const res = await fetch(url, { cache: "no-store" });
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch {
    return { ok: false, error: "Invalid JSON response", hint: text.slice(0, 200) };
  }
}

function escapeHtml(s) {
  return String(s)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function formatNumber(n) {
  if (n === null || n === undefined) return "—";
  const x = Number(n);
  if (!Number.isFinite(x)) return "—";
  return x.toLocaleString("en-AU");
}

function formatNumbersInText(input) {
  const s = String(input || "");
  return s.replace(/(\d{1,3}(?:,\d{3})+|\d{4,})/g, (m) => {
    const raw = m.replace(/,/g, "");
    const n = Number(raw);
    if (!Number.isFinite(n)) return m;
    return n.toLocaleString("en-AU");
  });
}


/* ---------------- Icon handling (case-tolerant) ---------------- */

function titleCaseWord(s) {
  if (!s) return s;
  return s.charAt(0).toUpperCase() + s.slice(1).toLowerCase();
}

function toFileKey(s) {
  return String(s || "")
    .trim()
    .toLowerCase()
    .replace(/['"]/g, "")
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "");
}

function iconCandidates(basePath, keyOrName) {
  const raw = String(keyOrName || "").trim();
  if (!raw) return [];

  const lower = raw.toLowerCase();
  const tc = titleCaseWord(raw);
  const noSpaces = raw.replace(/\s+/g, "");
  const lowerNoSpaces = lower.replace(/\s+/g, "");
  const fileKey = toFileKey(raw);
  const fileKeyNoUnderscore = fileKey.replace(/_/g, "");

  const uniq = new Set([
    `${basePath}${raw}.png`,
    `${basePath}${lower}.png`,
    `${basePath}${tc}.png`,
    `${basePath}${noSpaces}.png`,
    `${basePath}${lowerNoSpaces}.png`,
    `${basePath}${fileKey}.png`,
    `${basePath}${fileKeyNoUnderscore}.png`,
  ]);

  return Array.from(uniq).filter(p => !p.endsWith("/.png"));
}

function setImgWithFallback(imgEl, candidates, finalFallback) {
  if (!imgEl) return;

  let i = 0;
  const list = (candidates || []).slice();
  if (finalFallback) list.push(finalFallback);

  const tryNext = () => {
    if (i >= list.length) return;
    imgEl.src = list[i];
    i++;
  };

  imgEl.onerror = () => {
    if (i >= list.length) {
      imgEl.onerror = null;
      return;
    }
    tryNext();
  };

  tryNext();
}

function renderLastPull(el, lastPull) {
  if (!el) return;
  if (!lastPull || (!lastPull.local && !lastPull.utc)) {
    el.textContent = "";
    return;
  }
  const tz = lastPull.timezone || "UTC";
  const local = lastPull.local ? `${lastPull.local} (${tz})` : "—";
  const utc = lastPull.utc ? `${lastPull.utc} UTC` : "—";
  el.textContent = `Last data pull: ${local} • ${utc}`;
}

/* ---------------- Player avatar (cached server-side) ---------------- */

function setPlayerAvatar(rsn) {
  const img = qs("playerAvatar");
  if (!img) return;

  const name = String(rsn || "").trim();
  if (!name) {
    img.classList.add("hidden");
    img.removeAttribute("src");
    img.alt = "";
    return;
  }

    // RuneScape avatar endpoint expects underscores instead of spaces in the RSN
  const apiName = name.replace(/\s+/g, "_");
  const url = `api/avatar.php?player=${encodeURIComponent(apiName)}`;
  img.alt = `${name} avatar`;

  img.onload = () => {
    img.classList.remove("hidden");
  };

  img.onerror = () => {
    img.classList.add("hidden");
  };

  img.src = url;
}

/* ---------------- Clan avatars (cached only) ---------------- */

// Mirror api/avatar.php filename rules (spaces preserved, unsafe filesystem chars replaced)
function avatarSafeFilename(rsn) {
  let s = String(rsn || "").trim();
  if (!s) return "unknown";
  s = s.replace(/[\\\/\:\*\?\"\<\>\|]+/g, "_");
  s = s.replace(/\s+/g, " ").trim();
  return s || "unknown";
}

function getCachedAvatarUrl(rsn) {
  const safe = avatarSafeFilename(rsn);
  return `assets/avatars/${encodeURIComponent(safe)}.png`;
}


/* ---------------- Activity icon logic ---------------- */

const SKILLS = [
  "Attack","Defence","Strength","Constitution","Ranged","Prayer","Magic",
  "Cooking","Woodcutting","Fletching","Fishing","Firemaking","Crafting","Smithing","Mining",
  "Herblore","Agility","Thieving","Slayer","Farming","Runecrafting","Hunter","Construction",
  "Summoning","Dungeoneering","Divination","Invention","Archaeology","Necromancy",
];

function findSkillInText(text) {
  const t = String(text || "").toLowerCase();
  for (const s of SKILLS) {
    const low = s.toLowerCase();
    const re = new RegExp(`\\b${low}\\b`, "i");
    if (re.test(t)) return s;
  }
  return null;
}

function cleanItemNameForIcons(name) {
  let s = String(name || "");
  if (s.normalize) s = s.normalize("NFKC");
  s = s.replace(/\u00A0/g, " ").replace(/\s+/g, " ").trim();
  s = s.replace(/^["'“”‘’]+|["'“”‘’]+$/g, "").trim();
  s = s.replace(/\.\s*$/, "").trim();
  return s;
}

function extractDropItemNameFromText(activityText) {
  const t = String(activityText || "").trim();
  const m = t.match(/^I found a[n]?\s+(.+?)(?:\.\s*)?$/i);
  return (m && m[1]) ? cleanItemNameForIcons(m[1]) : null;
}

function extractDropItemNameFromDetails(details) {
  const d = String(details || "").trim();
  const m = d.match(/\bdropped a[n]?\s+(.+?)(?:\.\s*|$)/i);
  return (m && m[1]) ? cleanItemNameForIcons(m[1]) : null;
}

function classifyActivity(text, details) {
  const combined = `${text || ""} ${details || ""}`.toLowerCase();

  if (combined.includes("has completed") || combined.includes("completed:") || combined.includes("quest")) {
    return { kind: "quest" };
  }

  const itemFromText = extractDropItemNameFromText(text);
  const itemFromDetails = extractDropItemNameFromDetails(details);
  const itemName = itemFromText || itemFromDetails;
  if (itemName) return { kind: "drop", itemName };

  if (combined.includes("levelled") || combined.includes("leveled") || combined.includes("level up") || combined.includes("i am now level")) {
    const skill = findSkillInText(details) || findSkillInText(text) || null;
    return { kind: "level", skillName: skill };
  }

  // Skill XP / skill-related activity (e.g. "54,000,000 XP in Archaeology")
  // If we can confidently detect a skill name and the text mentions XP/experience,
  // show that skill's icon for the activity row.
  if (combined.includes("xp") || combined.includes("experience")) {
    const skill = findSkillInText(details) || findSkillInText(text) || null;
    if (skill) return { kind: "skill_xp", skillName: skill };
  }

  // Any other activity that names a skill: treat it as skill-related for icon purposes.
  {
    const skill = findSkillInText(details) || findSkillInText(text) || null;
    if (skill) return { kind: "skill", skillName: skill };
  }

  return { kind: "default" };
}

/* ---------------- Clan overview state ---------------- */
let clanData = null;
let clanFilter = "all";
let selectedClanXpPeriod = "7d";
const clanSkillTopCache = new Map(); // key: `${period}|${skill}` -> array

function populateClanXpPeriods(periods, currentValue) {
  const sel = qs("clanXpPeriod");
  if (!sel) return;
  sel.innerHTML = (periods || []).map(p => {
    const v = p.value;
    const label = p.label;
    const selected = v === currentValue ? " selected" : "";
    return `<option value="${escapeHtml(v)}"${selected}>${escapeHtml(label)}</option>`;
  }).join("");
}

async function fetchClanTopEarnersForSkill(skillName) {
  const params = getParams();
  const clanKey = params.clan;
  if (!clanKey || !skillName) return null;

  const cacheKey = `${selectedClanXpPeriod}|${skillName.toLowerCase()}`;
  if (clanSkillTopCache.has(cacheKey)) return clanSkillTopCache.get(cacheKey);

  const url = `${API.clanOverview}?clan=${encodeURIComponent(clanKey)}&period=${encodeURIComponent(selectedClanXpPeriod)}&skill=${encodeURIComponent(skillName)}`;
  const data = await fetchJson(url);
  if (data && data.ok && Array.isArray(data.top_earners)) {
    clanSkillTopCache.set(cacheKey, data.top_earners);
    return data.top_earners;
  }
  return null;
}

function renderClanXpLeaders() {
  const grid = qs("clanSkillLeaders");
  const meta = qs("clanXpMeta");
  if (!grid || !meta || !clanData?.ok) return;

  const xp = clanData.xp || {};
  const leaders = xp.leaders_by_skill || [];

  const start = xp.start_utc || "";
  const end = xp.end_utc || "";
  meta.textContent = start && end ? `Window: ${start} -> ${end} UTC` : "";

  if (!leaders.length) {
    grid.innerHTML = `<div class="muted">No XP snapshot data yet for this period.</div>`;
    return;
  }

  grid.innerHTML = leaders.map(r => {
    const skill = r.skill || "—";
    const key = r.skill_key || skill;
    const rsn = r.rsn ? r.rsn : "—";
    const gained = r.has_data ? `${formatNumber(r.gained_xp)} XP` : "—";

    // Each tile is clickable and expands to show top 10 for that skill
    return `
      <div class="leaderTile" data-skill="${escapeHtml(skill)}">
        <div class="leaderRow" role="button" aria-expanded="false">
          <img class="miniIcon" data-skill="${escapeHtml(skill)}" data-skillkey="${escapeHtml(key)}" alt="" />
          <div class="leaderSkill">${escapeHtml(skill)}</div>
          <div class="leaderMeta">${escapeHtml(rsn)} • ${escapeHtml(gained)}</div>
        </div>
        <div class="leaderExpand hidden" style="margin-top:8px; padding:10px 12px; border:1px solid rgba(255,255,255,0.08); border-radius: 12px; background: rgba(0,0,0,0.14);">
          <div class="muted">Loading top earners…</div>
        </div>
      </div>
    `;
  }).join("");

  grid.querySelectorAll("img.miniIcon").forEach(img => {
    const skillName = img.getAttribute("data-skill") || "";
    const skillKey = img.getAttribute("data-skillkey") || "";
    const candidates = [
      ...iconCandidates("assets/skills/", skillKey),
      ...iconCandidates("assets/skills/", skillName),
    ];
    setImgWithFallback(img, candidates, "assets/skills/_default.png");
  });

  // click leader row -> expand top 10 list
  grid.querySelectorAll(".leaderTile").forEach(tile => {
    const row = tile.querySelector(".leaderRow");
    const expand = tile.querySelector(".leaderExpand");
    const skill = tile.getAttribute("data-skill") || "";

    row.addEventListener("click", async () => {
      // Close others
      grid.querySelectorAll(".leaderExpand").forEach(el => { if (el !== expand) el.classList.add("hidden"); });
      grid.querySelectorAll(".leaderRow").forEach(el => { if (el !== row) el.setAttribute("aria-expanded", "false"); });

      const isOpen = !expand.classList.contains("hidden");
      if (isOpen) {
        expand.classList.add("hidden");
        row.setAttribute("aria-expanded", "false");
        return;
      }

      expand.classList.remove("hidden");
      row.setAttribute("aria-expanded", "true");

      // If we've already rendered a list, don't refetch
      if (expand.getAttribute("data-loaded") === "1") return;

      expand.innerHTML = `<div class="muted">Loading top earners…</div>`;
      try {
        const list = await fetchClanTopEarnersForSkill(skill);
        if (!list || !list.length) {
          expand.innerHTML = `<div class="muted">No XP gains recorded for ${escapeHtml(skill)} in this period.</div>`;
          expand.setAttribute("data-loaded", "1");
          return;
        }

        const rowsHtml = list.slice(0, 10).map((p, idx) => {
          const n = idx + 1;
          const rsn = p.rsn || "—";
          const gainedXp = p.gained_xp ?? null;
          return `
            <div style="display:flex; gap:10px; align-items:center; padding:6px 0; border-top:1px solid rgba(255,255,255,0.06);">
              <div style="width:22px; text-align:right; font-weight:900;">${n}.</div>
              <div style="font-weight:800;">${escapeHtml(rsn)}</div>
              <div class="muted" style="margin-left:auto; font-weight:800;">+${formatNumber(gainedXp)} XP</div>
            </div>
          `;
        }).join("");

        expand.innerHTML = `
          <div style="display:flex; align-items:baseline; gap:10px;">
            <div style="font-weight:900;">Top 10 • ${escapeHtml(skill)}</div>
            <div class="muted" style="margin-left:auto;">Period: ${escapeHtml(selectedClanXpPeriod)}</div>
          </div>
          <div style="margin-top:8px;">
            ${rowsHtml}
          </div>
        `;
        expand.setAttribute("data-loaded", "1");
      } catch (e) {
        expand.innerHTML = `<div class="muted">Couldn’t load earners for this skill.</div>`;
      }
    });
  });
}


function setFilter(newFilter) {
  clanFilter = newFilter;
  document.querySelectorAll(".segBtn").forEach(btn => {
    btn.classList.toggle("active", btn.dataset.filter === clanFilter);
  });
  renderMemberList();
}

function renderMemberList() {
  if (!clanData || !clanData.ok) return;

  const needle = normalise(qs("memberSearch").value).toLowerCase();
  const listEl = qs("memberList");

  let members = clanData.members || [];

  if (clanFilter === "capped") members = members.filter(m => m.capped);
  if (clanFilter === "uncapped") members = members.filter(m => !m.capped);

  if (needle) {
    members = members.filter(m =>
      (m.rsn || "").toLowerCase().includes(needle) ||
      (m.rank_name || "").toLowerCase().includes(needle)
    );
  }

  qs("clanStatus").textContent = `${members.length} shown`;

  listEl.innerHTML = members.map(m => {
    const badge = m.capped ? "Capped" : "Uncapped";
    const meta = m.rank_name ? escapeHtml(m.rank_name) : "—";
    return `
      <div class="memberCard clickable" data-rsn="${escapeHtml(m.rsn)}" title="Open player">
        <div class="memberLeft">
          <div class="memberHeader">
            <img class="memberAvatar" src="${getCachedAvatarUrl(m.rsn)}" alt="" onerror="this.remove()" />
            <div class="memberName">${escapeHtml(m.rsn)}</div>
          </div>
          <div class="memberMeta">${meta}</div>
        </div>
        <div class="badge">${badge}</div>
      </div>
    `;
  }).join("");

  Array.from(listEl.querySelectorAll(".memberCard.clickable")).forEach(node => {
    node.addEventListener("click", () => {
      const rsn = node.getAttribute("data-rsn") || "";
      if (rsn) setQuery({ player: rsn });
    });
  });
}

async function loadClanOverview(clanKey, period) {
  clanData = null;
  qs("clanSubheading").textContent = "Loading…";
  qs("statActive").textContent = "—";
  qs("statCapped").textContent = "—";
  qs("statUncapped").textContent = "—";
  qs("statPercent").textContent = "—";
  qs("clanStatus").textContent = "";
  qs("memberList").innerHTML = "";
  qs("clanLastPull").textContent = "";
  if (qs("clanXpMeta")) qs("clanXpMeta").textContent = "";
  if (qs("clanSkillLeaders")) qs("clanSkillLeaders").innerHTML = "";

  const usePeriod = period || selectedClanXpPeriod || "7d";
  const data = await fetchJson(`${API.clanOverview}?clan=${encodeURIComponent(clanKey)}&period=${encodeURIComponent(usePeriod)}`);
  if (!data || !data.ok) {
    qs("clanSubheading").textContent = `Error: ${data?.error || "request failed"}`;
    qs("clanStatus").textContent = data?.hint ? `Hint: ${data.hint}` : "";
    return;
  }

  clanData = data;

  const clanName = data.clan?.name || clanKey;
  const tz = data.week?.timezone || "UTC";
  const ws = data.week?.week_start_local || "";
  const we = data.week?.week_end_local || "";

  qs("clanSubheading").textContent = `${clanName} • Week: ${ws} → ${we} (${tz})`;

  qs("statActive").textContent = String(data.stats?.active_members ?? "0");
  qs("statCapped").textContent = String(data.stats?.capped ?? "0");
  qs("statUncapped").textContent = String(data.stats?.uncapped ?? "0");
  qs("statPercent").textContent = `${String(data.stats?.percent_capped ?? "0")}%`;

  renderLastPull(qs("clanLastPull"), data.last_pull);
  selectedClanXpPeriod = data.xp?.period || usePeriod;
  populateClanXpPeriods(data.xp_periods || [], selectedClanXpPeriod);
  renderClanXpLeaders();
  renderMemberList();
}

/* ---------------- Player state ---------------- */
let playerData = null;
let selectedXpPeriod = "7d";

function populateXpPeriods(periods, currentValue) {
  const sel = qs("xpPeriod");
  sel.innerHTML = (periods || []).map(p => {
    const v = p.value;
    const label = p.label;
    const selected = v === currentValue ? " selected" : "";
    return `<option value="${escapeHtml(v)}"${selected}>${escapeHtml(label)}</option>`;
  }).join("");
}

function renderCurrentSkills() {
  const gridEl = qs("skillsGrid");
  const cs = playerData?.current_skills;

  if (!cs || !cs.has_data) {
    gridEl.innerHTML = `<div class="muted">No skill snapshot data yet.</div>`;
    return;
  }

  const skills = cs.skills || [];
  gridEl.innerHTML = skills.map(s => {
    const name = s.skill || "—";
    const key = s.skill_key || name;
    const level = Number(s.level ?? 0);
    const xp = Number(s.xp ?? 0);

    const vLevel = (window.TrackerSkills && typeof window.TrackerSkills.virtualLevelFromXp === 'function')
      ? window.TrackerSkills.virtualLevelFromXp(xp, name)
      : level;

    const maxV = (window.TrackerSkills && typeof window.TrackerSkills.maxVirtualLevelForSkill === 'function')
      ? window.TrackerSkills.maxVirtualLevelForSkill(name)
      : 120;

    const displayLevel = Math.max(level, vLevel);
    const isVirtualShown = (displayLevel > level);

    const is200m = xp >= 200_000_000;
    const isMaxVirtual = displayLevel >= maxV;

    // Border tiers (your latest rules)
    let tierClass = "";
    if (is200m) tierClass = "gold";
    else if (displayLevel >= 120) tierClass = "silver";
    else if (displayLevel >= 99) tierClass = "bronze";

    // Badge precedence: 200m wins (show ONLY 200m badge)
    let badgeHtml = "";
    if (is200m) {
      badgeHtml = `<img class="skillBadge" src="assets/badges/200m.png" alt="200m" />`;
    } else if (isMaxVirtual) {
      badgeHtml = `<img class="skillBadge" src="assets/badges/max_virtual.png" alt="Max virtual" />`;
    }

    return `
      <div class="skillCard ${tierClass}">
        <div class="skillIconWrap">
          <img class="skillIcon" data-skill="${escapeHtml(name)}" data-skillkey="${escapeHtml(key)}" alt="" />
          ${badgeHtml}
        </div>
        <div class="skillInfo">
          <div class="skillTitle">${escapeHtml(name)}</div>
          <div class="skillLevel">Level ${escapeHtml(String(displayLevel || "—"))}${isVirtualShown ? ` <span class="pill">Virtual</span>` : ""}</div>
          <div class="skillXp">${formatNumber(xp)} XP</div>
        </div>
      </div>
    `;
  }).join("");

  gridEl.querySelectorAll("img.skillIcon").forEach(img => {
    const skillName = img.getAttribute("data-skill") || "";
    const skillKey = img.getAttribute("data-skillkey") || "";
    const candidates = [
      ...iconCandidates("assets/skills/", skillKey),
      ...iconCandidates("assets/skills/", skillName),
    ];
    setImgWithFallback(img, candidates, "assets/skills/_default.png");
  });
}

function renderTopXpSkills() {
  const listEl = qs("skillList");
  if (!listEl) return;

  const xp = playerData?.xp;
  const top = xp?.top_skills || [];

  if (!xp || !xp.has_data) {
    listEl.innerHTML = `<div class="muted">No XP snapshot data for this period yet.</div>`;
    return;
  }

  if (!top.length) {
    listEl.innerHTML = `<div class="muted">No XP gains recorded for this period.</div>`;
    return;
  }

  listEl.innerHTML = top.map(row => {
    const name = row.skill || "—";
    const key = row.skill_key || name;
    const gained = row.gained_xp ?? null;

    return `
      <div class="skillRow">
        <img class="miniIcon" data-skill="${escapeHtml(name)}" data-skillkey="${escapeHtml(key)}" alt="" />
        <div class="skillName">${escapeHtml(name)}</div>
        <div class="skillXp">+${formatNumber(gained)}</div>
      </div>
    `;
  }).join("");

  listEl.querySelectorAll("img.miniIcon").forEach(img => {
    const skillName = img.getAttribute("data-skill") || "";
    const skillKey = img.getAttribute("data-skillkey") || "";
    const candidates = [
      ...iconCandidates("assets/skills/", skillKey),
      ...iconCandidates("assets/skills/", skillName),
    ];
    setImgWithFallback(img, candidates, "assets/skills/_default.png");
  });
}

function renderPlayer() {
  if (!playerData || !playerData.ok) return;

  const m = playerData.member;
  const c = playerData.clan;
  const tzLabel = (c && c.timezone) ? c.timezone : "";
  const week = playerData.week;

  qs("playerName").textContent = m?.rsn || "—";
  setPlayerAvatar(m?.rsn || "");
  qs("playerSubheading").textContent =
    `${c?.name || c?.key || "Clan"} • Week: ${week?.week_start_local || ""} → ${week?.week_end_local || ""} (${week?.timezone || "UTC"})`;

  const status = (m?.is_active ? "Active" : "Inactive");
  const rank = m?.rank_name ? m.rank_name : "—";
  qs("playerMeta").textContent = `Clan: ${c?.name || "—"} • Rank: ${rank} • Status: ${status}`;

  qs("pCap").textContent = playerData.cap?.capped ? "Capped" : "Uncapped";
  qs("pVisit").textContent = playerData.visit?.visited ? "Visited" : "Not visited";

  const xp = playerData.xp;
  qs("pXpGained").textContent = xp?.has_data ? formatNumber(xp.gained_total_xp) : "—";

  renderTopXpSkills();

  // Activity log (icons + coloured rows)
  const activityList = qs("activityList");
  const activity = playerData.recent_activity || [];
  qs("activityStatus").textContent = `${activity.length} items`;

  if (activity.length) {
    activityList.innerHTML = activity.map(a => {
      const when = a.activity_date_local || a.activity_date_utc || a.announced_at_local || a.announced_at_utc || "";
      const text = formatNumbersInText(a.text || "");
      const details = formatNumbersInText(a.details || "");

      const info = classifyActivity(text, details);

      const rowClass =
        info.kind === "drop"  ? "activityRow activity-drop"  :
        info.kind === "level" ? "activityRow activity-level" :
        info.kind === "quest" ? "activityRow activity-quest" :
                                "activityRow";

      return `
        <div class="${rowClass}">
          <img class="miniIcon"
               data-kind="${escapeHtml(info.kind)}"
               data-skill="${escapeHtml(info.skillName || "")}"
               data-item="${escapeHtml(info.itemName || "")}"
               alt="" />
          <div class="activityMain">
            <div class="activityText">${escapeHtml(text)}</div>
            ${details ? `<div class="activityDetails">${escapeHtml(details)}</div>` : ""}
            <div class="activityDate">${escapeHtml(when)} ${escapeHtml(tzLabel || "UTC")}</div>
          </div>
        </div>
      `;
    }).join("");

    activityList.querySelectorAll("img.miniIcon").forEach(img => {
      const kind = img.getAttribute("data-kind") || "default";
      const skillName = img.getAttribute("data-skill") || "";
      const itemName = cleanItemNameForIcons(img.getAttribute("data-item") || "");

      if (kind === "level") {
        const candidates = skillName ? [...iconCandidates("assets/skills/", skillName)] : [];
        candidates.push("assets/activity/level.png");
        setImgWithFallback(img, candidates, "assets/activity/default.png");
        return;
      }

      if (kind === "skill_xp" || kind === "skill") {
        const candidates = skillName ? [...iconCandidates("assets/skills/", skillName)] : [];
        candidates.push("assets/activity/default.png");
        setImgWithFallback(img, candidates, "assets/activity/default.png");
        return;
      }

      if (kind === "drop") {
        const candidates = [];
        if (itemName) {
          candidates.push(`/api/wiki_item_icon.php?item=${encodeURIComponent(itemName)}`);
          const underscored = itemName.replace(/\s+/g, "_");
          candidates.push(`https://runescape.wiki/images/${encodeURIComponent(underscored)}.png`);
          candidates.push(
            ...iconCandidates("assets/items/", itemName),
            ...iconCandidates("assets/items/", toFileKey(itemName)),
            ...iconCandidates("assets/items/", toFileKey(itemName).replace(/_/g, ""))
          );
        }
        candidates.push("assets/activity/default.png");
        setImgWithFallback(img, candidates, "assets/activity/default.png");
        return;
      }

      if (kind === "quest") {
        setImgWithFallback(img, ["assets/activity/quest.png"], "assets/activity/default.png");
        return;
      }

      setImgWithFallback(img, ["assets/activity/default.png"], "assets/activity/default.png");
    });
  } else {
    activityList.innerHTML = `<div class="muted">No recent activity recorded.</div>`;
  }

  renderCurrentSkills();
  renderLastPull(qs("playerLastPull"), playerData.last_pull);
}

async function loadPlayer(rsn, period) {
  playerData = null;

  qs("playerSubheading").textContent = "Loading…";
  qs("playerName").textContent = "—";
  setPlayerAvatar("");
  qs("playerMeta").textContent = "—";
  qs("playerError").textContent = "";
  qs("playerLastPull").textContent = "";

  const url = `${API.player}?player=${encodeURIComponent(rsn)}&period=${encodeURIComponent(period || "7d")}`;
  const data = await fetchJson(url);

  if (!data || !data.ok) {
    qs("playerSubheading").textContent = `Error: ${data?.error || "request failed"}`;
    qs("playerError").textContent = data?.hint ? `Hint: ${data.hint}` : "";
    return;
  }

  playerData = data;
  selectedXpPeriod = data.xp?.period || period || "7d";
  populateXpPeriods(data.xp_periods || [], selectedXpPeriod);
  renderPlayer();

  // If we haven't collected XP recently, trigger a refresh for this player.
  // Then reload the player data once.
  try {
    const key = String(rsn || "").trim().toLowerCase();
    if (!key) return;

    const lastSnapUtc =
      data?.last_pull?.sources?.last_xp_snapshot_utc ||
      data?.current_skills?.captured_at_utc ||
      null;

    const age = utcAgeSeconds(lastSnapUtc);
    const needsRefresh = (age === null) || (age > 300);

    if (needsRefresh && !xpRefreshAttempted.has(key)) {
      xpRefreshAttempted.add(key);

      const rsnForRefresh = String(rsn || "").trim().replace(/\s+/g, "_");
      const refreshUrl = `${API.refreshPlayerXp}?player=${encodeURIComponent(rsnForRefresh)}`;
      const refresh = await fetchJson(refreshUrl);

      if (refresh && refresh.ok && refresh.refreshed) {
        // re-load with the same period
        loadPlayer(rsn, selectedXpPeriod);
      }
    }
  } catch {
    // ignore refresh errors
  }
}

/* ---------------- Render views ---------------- */
function render() {
  const { clan, player } = getParams();

  const landing = qs("landingCard");
  const viewClan = qs("viewClan");
  const viewPlayer = qs("viewPlayer");
  const notice = qs("notice");

  if (player) {
    show(landing, false);
    show(viewClan, false);
    show(viewPlayer, true);
    loadPlayer(player, selectedXpPeriod);
    return;
  }

  if (clan) {
    show(landing, false);
    show(viewPlayer, false);
    show(viewClan, true);
    loadClanOverview(clan, selectedClanXpPeriod);
    return;
  }

  show(viewClan, false);
  show(viewPlayer, false);
  show(landing, true);
  notice.textContent = "Tip: start typing to search, or paste a clan key / RSN.";
}

/* ---------------- Typeahead component + wiring ---------------- */
/* (unchanged from your current file except the row class above) */

function createTypeahead({ inputEl, listEl, minChars = 1, maxItems = 8, fetchItems, renderItem, onSelectValue }) {
  let items = [];
  let activeIndex = -1;
  let lastQueryKey = "";

  function close() {
    show(listEl, false);
    inputEl.setAttribute("aria-expanded", "false");
    activeIndex = -1;
    listEl.innerHTML = "";
  }

  function open() {
    show(listEl, true);
    inputEl.setAttribute("aria-expanded", "true");
  }

  function setActive(idx) {
    activeIndex = idx;
    const nodes = Array.from(listEl.querySelectorAll(".item"));
    nodes.forEach((n, i) => n.classList.toggle("active", i === activeIndex));
    if (activeIndex >= 0 && nodes[activeIndex]) nodes[activeIndex].scrollIntoView({ block: "nearest" });
  }

  function choose(idx) {
    const item = items[idx];
    if (!item) return;
    const r = renderItem(item);
    inputEl.value = r.value;
    close();
    onSelectValue(r.value);
  }

  async function update(queryRaw) {
    const q = normalise(queryRaw);
    const qKey = q.toLowerCase();
    if (qKey === lastQueryKey) return;
    lastQueryKey = qKey;

    if (q.length < minChars) { close(); return; }

    const itemsRaw = await fetchItems(q);
    items = (itemsRaw || []).slice(0, maxItems);

    if (!items.length) { close(); return; }

    listEl.innerHTML = items.map((it, idx) => {
      const r = renderItem(it);
      const primary = escapeHtml(r.primary || "");
      const secondary = escapeHtml(r.secondary || "");
      const badge = escapeHtml(r.badge || "");
      return `
        <div class="item" role="option" data-idx="${idx}">
          <div class="left">
            <div class="primary">${primary}</div>
            ${secondary ? `<div class="secondaryText">${secondary}</div>` : ""}
          </div>
          ${badge ? `<div class="badge">${badge}</div>` : ""}
        </div>
      `;
    }).join("");

    Array.from(listEl.querySelectorAll(".item")).forEach(node => {
      node.addEventListener("mousedown", (e) => {
        e.preventDefault();
        const idx = Number(node.getAttribute("data-idx"));
        choose(idx);
      });
    });

    open();
    setActive(0);
  }

  const debouncedUpdate = debounce(update, 180);
  inputEl.addEventListener("input", () => debouncedUpdate(inputEl.value));
  inputEl.addEventListener("focus", () => {
    if (normalise(inputEl.value).length >= minChars) debouncedUpdate(inputEl.value);
  });
  inputEl.addEventListener("blur", () => setTimeout(close, 120));

  inputEl.addEventListener("keydown", (e) => {
    const isOpen = !listEl.classList.contains("hidden");
    if (!isOpen && (e.key === "ArrowDown" || e.key === "ArrowUp")) { debouncedUpdate(inputEl.value); return; }
    if (!isOpen) return;

    if (e.key === "ArrowDown") { e.preventDefault(); setActive(Math.min(activeIndex + 1, items.length - 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setActive(Math.max(activeIndex - 1, 0)); }
    else if (e.key === "Enter") { e.preventDefault(); if (activeIndex >= 0) choose(activeIndex); }
    else if (e.key === "Escape") { e.preventDefault(); close(); }
  });
}

async function searchClans(q) {
  const data = await fetchJson(`${API.clans}?q=${encodeURIComponent(q)}`);
  return Array.isArray(data) ? data : [];
}

async function searchPlayers(q) {
  const data = await fetchJson(`${API.players}?q=${encodeURIComponent(q)}`);
  return Array.isArray(data) ? data : [];
}

function wireUI() {
  const clanKey = qs("clanKey");
  const playerRsn = qs("playerRsn");

  createTypeahead({
    inputEl: clanKey,
    listEl: qs("clanList"),
    fetchItems: searchClans,
    renderItem: (c) => ({
      primary: `${c.name || c.key}`,
      secondary: c.key ? `Key: ${c.key}` : "",
      badge: typeof c.members === "number" ? `${c.members} members` : "",
      value: c.key || c.name || "",
    }),
    onSelectValue: (value) => setQuery({ clan: value }),
  });

  createTypeahead({
    inputEl: playerRsn,
    listEl: qs("playerList"),
    fetchItems: searchPlayers,
    renderItem: (p) => ({
      primary: p.rsn,
      secondary: p.clan ? `Clan: ${p.clan}` : "",
      badge: p.status ? p.status : "",
      value: p.rsn || "",
    }),
    onSelectValue: (value) => setQuery({ player: value }),
  });

  qs("btnClan").addEventListener("click", () => {
    const v = normalise(clanKey.value);
    if (!v) return qs("notice").textContent = "Please enter a clan key.";
    setQuery({ clan: v });
  });

  qs("btnPlayer").addEventListener("click", () => {
    const v = normalise(playerRsn.value);
    if (!v) return qs("notice").textContent = "Please enter a player RSN.";
    setQuery({ player: v });
  });

  qs("backFromClan").addEventListener("click", clearQuery);
  qs("backFromPlayer").addEventListener("click", clearQuery);

  qs("memberSearch").addEventListener("input", debounce(renderMemberList, 120));
  document.querySelectorAll(".segBtn").forEach(btn => btn.addEventListener("click", () => setFilter(btn.dataset.filter)));

    const clanXpSel = qs("clanXpPeriod");
  if (clanXpSel) {
    clanXpSel.addEventListener("change", () => {
      const v = clanXpSel.value || "7d";
      selectedClanXpPeriod = v;
      const { clan } = getParams();
      if (clan) loadClanOverview(clan, selectedClanXpPeriod);
    });
  }

qs("xpPeriod").addEventListener("change", () => {
    const v = qs("xpPeriod").value || "7d";
    selectedXpPeriod = v;
    const { player } = getParams();
    if (player) loadPlayer(player, selectedXpPeriod);
  });

  window.addEventListener("popstate", render);
}

wireUI();
render();
