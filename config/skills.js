// config/skills.js
// Update this file if RuneScape changes skill caps or adds elite skills.
// Exposes a global: window.TrackerSkills

(function () {
  // Elite skills (virtual cap may exceed 120). Currently only Invention.
  // If Jagex adds more elite skills, add them here and define an XP table below.
  const ELITE_SKILLS = [
    'Invention',
  ];

  // Skills that have a true level 120 cap (not just virtual levels)
  // This can be updated independently of the virtual XP tables.
  const TRUE_120_SKILLS = [
    'Herblore',
    'Thieving',
    'Slayer',
    'Farming',
    'Dungeoneering',
    'Invention',
    'Archaeology',
    'Necromancy',
  ];

  // XP thresholds for non-elite skills (virtual levels 100-120)
  // Values are XP required to reach that level.
  const NON_ELITE_VIRTUAL_XP = {
    100: 14391160,
    101: 15889109,
    102: 17542976,
    103: 19368992,
    104: 21385073,
    105: 23611006,
    106: 26068632,
    107: 28782069,
    108: 31777943,
    109: 35085654,
    110: 38737661,
    111: 42769801,
    112: 47221641,
    113: 52136869,
    114: 57563718,
    115: 63555443,
    116: 70170840,
    117: 77474828,
    118: 85539082,
    119: 94442737,
    120: 104273167,
  };

  // Elite XP tables (virtual levels above 120). Key: skill name -> map(level -> xp)
  const ELITE_XP_TABLES = {
    Invention: {
      121: 83370445,
      122: 86186124,
      123: 89066630,
      124: 92012904,
      125: 95025896,
      126: 98106559,
      127: 101255855,
      128: 104474750,
      129: 107764216,
      130: 111125230,
      131: 114558777,
      132: 118065845,
      133: 121647430,
      134: 125304532,
      135: 129038159,
      136: 132849323,
      137: 136739041,
      138: 140708338,
      139: 144758242,
      140: 148889790,
      141: 153104021,
      142: 157401983,
      143: 161784728,
      144: 166253312,
      145: 170808801,
      146: 175452262,
      147: 180184770,
      148: 185007406,
      149: 189921255,
      150: 194927409,
    },
  };

  function isEliteSkill(skillName) {
    const s = String(skillName || '').trim();
    return ELITE_SKILLS.some(x => x.toLowerCase() === s.toLowerCase());
  }

  function maxVirtualLevelForSkill(skillName) {
    const s = String(skillName || '').trim();
    if (isEliteSkill(s)) {
      const table = ELITE_XP_TABLES[s] || ELITE_XP_TABLES[Object.keys(ELITE_XP_TABLES).find(k => k.toLowerCase() === s.toLowerCase())];
      if (!table) return 120;
      return Math.max(...Object.keys(table).map(n => parseInt(n, 10)).filter(n => Number.isFinite(n)));
    }
    return 120;
  }

  // Compute virtual level from XP using the configured tables.
  // For non-elite skills, returns 99 if XP < level 100 threshold.
  // For elite skills (e.g., Invention), returns 120 if XP < level 121 threshold.
  function virtualLevelFromXp(xp, skillName) {
    const x = Number(xp);
    if (!Number.isFinite(x) || x <= 0) return 1;

    const s = String(skillName || '').trim();

    // Elite skill virtual levels above 120
    if (isEliteSkill(s)) {
      const key = Object.keys(ELITE_XP_TABLES).find(k => k.toLowerCase() === s.toLowerCase());
      const table = key ? ELITE_XP_TABLES[key] : null;
      if (!table) return 120;
            // If XP is below the first elite virtual threshold (e.g. Invention 121),
      // do NOT infer level 120. Let the UI use the snapshot real level instead.
      const firstReq = Math.min(...Object.values(table).map(Number).filter(n => Number.isFinite(n)));
      if (Number.isFinite(firstReq) && x < firstReq) return 0;
let best = 120;
      for (const [lvlStr, req] of Object.entries(table)) {
        const lvl = parseInt(lvlStr, 10);
        if (!Number.isFinite(lvl)) continue;
        if (x >= Number(req)) best = Math.max(best, lvl);
      }
      return best;
    }

    // Non-elite: virtual 100-120
    let best = 99;
    for (const [lvlStr, req] of Object.entries(NON_ELITE_VIRTUAL_XP)) {
      const lvl = parseInt(lvlStr, 10);
      if (!Number.isFinite(lvl)) continue;
      if (x >= Number(req)) best = Math.max(best, lvl);
    }
    return best;
  }

  function isTrue120Skill(skillName) {
    const s = String(skillName || '').trim();
    return TRUE_120_SKILLS.some(x => x.toLowerCase() === s.toLowerCase());
  }

  // Expose
  window.TrackerSkills = {
    ELITE_SKILLS,
    TRUE_120_SKILLS,
    NON_ELITE_VIRTUAL_XP,
    ELITE_XP_TABLES,
    isEliteSkill,
    isTrue120Skill,
    maxVirtualLevelForSkill,
    virtualLevelFromXp,
  };
})();

  // Debug version stamp
  window.TrackerSkillsVersion = '20260120220643';
