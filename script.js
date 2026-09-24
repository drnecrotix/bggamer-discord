const DISCORD_CONFIG = {
  inviteUrl: "https://discord.gg/PFkjeKBuxH",
  inviteCode: "PFkjeKBuxH",
  guildId: "114667416247599110",
  rulesChannelId: "1035251098031755294",
  rulesUrl: "https://discord.com/channels/114667416247599110/1035251098031755294",
  newsChannelId: "506928759509745664",
  rssFeedUrl: "https://bg-gamer.com/category/news/feed/",
  rssFeedLabel: "BG-GAMER.com",
  inviteApiEndpoint: "",
  widgetEndpoint: "",
  eventsEndpoint: "",
  newsEndpoint: "",
  insightsEndpoint: "",
  refreshIntervalMs: 30000,
  fallbackStats: {
    members: 2814,
    online: 58,
    boosts: 25,
    channels: 6,
    premiumTier: 2,
    serverName: "BG-GAMER",
    description:
      "BG-GAMER is a gaming community for players, streamers, and fans looking for news, discussions, clips, memes, events, LFG channels, streamer spotlights, and late-night voice chats.",
    entryChannel: "#home-начало",
    topChannels: ["#Stage", "#Streaming", "#AFK"],
    mostActiveTextChannel: "#general",
    mostActiveTextChannelMessages: 184,
    languages: ["Bulgarian", "English"],
    activeVoiceChannel: "Gaming VC",
    activeVoiceCount: 2,
    topGame: "Counter-Strike 2",
    traits: ["Video Games", "Bulgaria", "Community", "Tech", "Talk"],
    verificationEnabled: true,
    activityBins: [9, 13, 11, 15, 18, 26, 31, 24, 18, 14, 10, 8, 12, 22, 27, 34, 30, 41, 38, 33, 28, 19, 16, 21],
    onlineMembers: [],
    news: [
      {
        id: "demo-news-1",
        title: "BG-GAMER RSS feed is syncing",
        body: "If the website feed is temporarily unavailable, this panel will retry automatically and render the latest published posts once it responds again.",
        authorName: "BG-GAMER",
        postedAt: null,
        jumpUrl: ""
      }
    ],
    events: [
      {
        id: "demo-event-1",
        title: "Community game night",
        description: "Connect the events proxy to pull the real scheduled activity from Discord.",
        location: "Discord scheduled events",
        startAt: null,
        endAt: null,
        status: "scheduled",
        interestedCount: 0
      }
    ]
  }
};

const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
const numberFormatter = new Intl.NumberFormat();
const timeFormatter = new Intl.DateTimeFormat("bg-BG", {
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit"
});
const eventDateFormatter = new Intl.DateTimeFormat("bg-BG", {
  day: "2-digit",
  month: "short",
  hour: "2-digit",
  minute: "2-digit"
});
const newsDateFormatter = new Intl.DateTimeFormat("bg-BG", {
  day: "2-digit",
  month: "short",
  hour: "2-digit",
  minute: "2-digit"
});

let latestStats = null;
let cachedStats = null;
let activeZoneId = "welcome";
let serverChartAnimationFrame = 0;
let serverChartState = [];

document.addEventListener("DOMContentLoaded", async () => {
  normalizeLegacyHomepageNav();
  syncDiscordLinks();
  syncRulesLinks();
  syncInviteTexts();
  initRevealObserver();
  initCounterObserver();
  initServerNavigator();
  initHeroParallax();

  const stats = await getDiscordStats();
  applyDiscordStats(stats);

  window.setInterval(refreshDiscordStats, DISCORD_CONFIG.refreshIntervalMs);
});

function normalizeLegacyHomepageNav() {
  const navbar = document.querySelector(".topbar .navbar");

  if (!navbar || document.querySelector(".navbar[data-site-nav]")) {
    return;
  }

  document.body.dataset.navPage = "lobby";
  document.body.dataset.navCta = "invite";
  document.body.dataset.inviteUrl = DISCORD_CONFIG.inviteUrl;
  document.body.dataset.rulesUrl = DISCORD_CONFIG.rulesUrl;
  navbar.setAttribute("data-site-nav", "");

  const brandSubtitle = navbar.querySelector(".brand__text small");

  if (brandSubtitle) {
    brandSubtitle.textContent = "Gaming community";
  }

  const collapse = navbar.querySelector(".navbar-collapse");
  const navList = collapse?.querySelector(".navbar-nav");

  if (navList) {
    navList.className = "navbar-nav navbar-nav--site align-items-lg-center mb-3 mb-lg-0";
    navList.setAttribute("data-site-nav-list", "");
    navList.innerHTML = [
      ['#top', 'Lobby', ' aria-current="page"'],
      ['#activity', 'Activity', ''],
      ['#bots', 'Bots', ''],
      ['bans/', 'Bans', ''],
      ['appeal/', 'Appeal', ''],
      [DISCORD_CONFIG.rulesUrl, 'Rules', ''],
      ['mod/', 'Mod Panel', '']
    ]
      .map(([href, label, current]) => `<li class="nav-item"><a class="nav-link" href="${href}"${current}>${label}</a></li>`)
      .join("");
  }

  if (collapse) {
    let actionSlot = collapse.querySelector("[data-site-nav-actions]");

    if (!actionSlot) {
      actionSlot = document.createElement("div");
      actionSlot.className = "site-nav__actions";
      actionSlot.setAttribute("data-site-nav-actions", "");
      collapse.append(actionSlot);
    }

    actionSlot.innerHTML = `<a class="btn btn-brand navbar-cta ms-lg-4" href="${DISCORD_CONFIG.inviteUrl}" data-discord-link>Open Invite</a>`;
  }

  if (!Array.from(document.scripts).some((script) => (script.src || "").includes("assets/site-nav.js"))) {
    const syncScript = document.createElement("script");
    syncScript.src = new URL("assets/site-nav.js?v=20260714-4", window.location.href).toString();
    document.body.append(syncScript);
  }
}

function syncDiscordLinks() {
  document.querySelectorAll("[data-discord-link]").forEach((link) => {
    link.setAttribute("href", DISCORD_CONFIG.inviteUrl);
  });
}

function syncRulesLinks() {
  const rulesUrl = DISCORD_CONFIG.rulesUrl
    || (DISCORD_CONFIG.guildId && DISCORD_CONFIG.rulesChannelId
      ? `https://discord.com/channels/${encodeURIComponent(DISCORD_CONFIG.guildId)}/${encodeURIComponent(DISCORD_CONFIG.rulesChannelId)}`
      : DISCORD_CONFIG.inviteUrl);

  document.querySelectorAll("[data-rules-link]").forEach((link) => {
    link.setAttribute("href", rulesUrl);
  });
}

function syncInviteTexts() {
  const compactInvite = DISCORD_CONFIG.inviteUrl.replace(/^https?:\/\//i, "");
  setText("heroInviteText", compactInvite);
  setText("ctaInviteText", DISCORD_CONFIG.inviteUrl);
}

async function refreshDiscordStats() {
  const stats = await getDiscordStats();
  applyDiscordStats(stats);
}

async function getDiscordStats() {
  const fallback = { ...DISCORD_CONFIG.fallbackStats };
  const updatedAt = new Date();

  const [inviteResult, widgetResult, eventsResult, newsResult, insightsResult] = await Promise.allSettled([
    getInviteProfile(),
    getWidgetProfile(),
    getScheduledEvents(),
    getServerNews(),
    getServerInsights()
  ]);

  const inviteProfile = inviteResult.status === "fulfilled" ? inviteResult.value : null;
  const widgetProfile = widgetResult.status === "fulfilled" ? widgetResult.value : null;
  const scheduledEvents = eventsResult.status === "fulfilled" ? eventsResult.value : null;
  const newsFeed = newsResult.status === "fulfilled" ? newsResult.value : null;
  const insightsProfile = insightsResult.status === "fulfilled" ? insightsResult.value : null;
  const liveSourceCount = Number(Boolean(inviteProfile)) + Number(Boolean(widgetProfile));

  if (liveSourceCount === 0 && cachedStats) {
    return {
      ...cachedStats,
      events: scheduledEvents ?? cachedStats.events,
      eventsMeta: buildEventsMeta(scheduledEvents ?? cachedStats.events),
      news: newsFeed ?? cachedStats.news,
      newsMeta: buildNewsMeta(newsFeed ?? cachedStats.news),
      meta: {
        state: "partial",
        label: "Using last sync",
        source: "Discord sync failed, keeping the last successful values on screen",
        updatedAt
      }
    };
  }

  const stats = mergeStats(inviteProfile, widgetProfile, insightsProfile, fallback);
  stats.events = scheduledEvents;
  stats.eventsMeta = buildEventsMeta(scheduledEvents);
  stats.news = newsFeed;
  stats.newsMeta = buildNewsMeta(newsFeed);

  if (liveSourceCount === 0) {
    stats.meta = {
      state: "demo",
      label: "Demo data",
      source: "Live Discord sources are unavailable right now",
      updatedAt
    };
    return stats;
  }

  if (liveSourceCount === 1) {
    stats.meta = {
      state: "partial",
      label: "Partially live",
      source: inviteProfile
        ? "Live invite profile + fallback widget-derived details"
        : "Live widget profile + fallback invite-derived details",
      updatedAt
    };
    return stats;
  }

  stats.meta = {
    state: "live",
    label: "Live sync",
    source: "Discord invite, widget, and connected live feeds",
    updatedAt
  };

  return stats;
}

async function getInviteProfile() {
  const endpoint = resolveInviteApiEndpoint();

  if (!endpoint) {
    return null;
  }

  return fetchJson(endpoint);
}

async function getWidgetProfile() {
  const endpoint = resolveWidgetEndpoint();

  if (!endpoint) {
    return null;
  }

  return fetchJson(endpoint);
}

async function getScheduledEvents() {
  const endpoint = resolveEventsEndpoint();

  if (!endpoint) {
    return null;
  }

  const payload = await fetchJson(endpoint);
  return normalizeScheduledEvents(payload);
}

async function getServerNews() {
  const endpoint = resolveNewsEndpoint();

  if (!endpoint) {
    return null;
  }

  if (looksLikeRssEndpoint(endpoint)) {
    const payload = await fetchText(endpoint);
    return normalizeRssNewsFeed(payload);
  }

  const payload = await fetchJson(endpoint);
  return normalizeNewsFeed(payload);
}

async function getServerInsights() {
  const endpoint = resolveInsightsEndpoint();

  if (!endpoint) {
    return null;
  }

  return fetchJson(endpoint);
}

function resolveInviteApiEndpoint() {
  if (DISCORD_CONFIG.inviteApiEndpoint) {
    return DISCORD_CONFIG.inviteApiEndpoint;
  }

  const inviteCode = DISCORD_CONFIG.inviteCode || extractInviteCode(DISCORD_CONFIG.inviteUrl);

  if (!inviteCode) {
    return "";
  }

  return `https://discord.com/api/v9/invites/${encodeURIComponent(inviteCode)}?with_counts=true`;
}

function resolveWidgetEndpoint() {
  if (DISCORD_CONFIG.widgetEndpoint) {
    return DISCORD_CONFIG.widgetEndpoint;
  }

  if (!DISCORD_CONFIG.guildId) {
    return "";
  }

  return `https://discord.com/api/guilds/${encodeURIComponent(DISCORD_CONFIG.guildId)}/widget.json`;
}

function resolveEventsEndpoint() {
  return resolveProxyEndpoint(DISCORD_CONFIG.eventsEndpoint || "");
}

function resolveNewsEndpoint() {
  if (DISCORD_CONFIG.newsEndpoint) {
    return resolveProxyEndpoint(DISCORD_CONFIG.newsEndpoint);
  }

  return DISCORD_CONFIG.rssFeedUrl || "";
}

function resolveInsightsEndpoint() {
  return resolveProxyEndpoint(DISCORD_CONFIG.insightsEndpoint || "");
}

function resolveProxyEndpoint(endpoint) {
  if (!endpoint) {
    return "";
  }

  if (/^https?:\/\//i.test(endpoint)) {
    return endpoint;
  }

  const isLocalHost = /^(localhost|127\.0\.0\.1)$/i.test(window.location.hostname);

  if (isLocalHost && endpoint.startsWith("/api/")) {
    return `http://127.0.0.1:8787${endpoint}`;
  }

  return endpoint;
}

function extractInviteCode(inviteUrl) {
  if (!inviteUrl) {
    return "";
  }

  try {
    const url = new URL(inviteUrl);
    const parts = url.pathname.split("/").filter(Boolean);
    return parts[parts.length - 1] ?? "";
  } catch (error) {
    return "";
  }
}

async function fetchJson(url) {
  const response = await fetch(url, {
    method: "GET",
    headers: {
      Accept: "application/json"
    }
  });

  if (!response.ok) {
    throw new Error(`Request failed with status ${response.status}`);
  }

  return response.json();
}

async function fetchText(url) {
  const response = await fetch(url, {
    method: "GET",
    headers: {
      Accept: "application/rss+xml, application/xml, text/xml;q=0.9, text/plain;q=0.8, */*;q=0.5"
    }
  });

  if (!response.ok) {
    throw new Error(`Request failed with status ${response.status}`);
  }

  return response.text();
}

function looksLikeRssEndpoint(url) {
  return /\/feed\/?($|\?)/i.test(url) || /\.xml($|\?)/i.test(url);
}

function mergeStats(inviteProfile, widgetProfile, insightsProfile, fallback) {
  const inviteGuild = inviteProfile?.guild ?? inviteProfile?.guild_preview ?? {};
  const inviteServerProfile = inviteProfile?.profile ?? {};
  const widgetChannels = Array.isArray(widgetProfile?.channels) ? widgetProfile.channels : [];
  const widgetMembers = Array.isArray(widgetProfile?.members) ? widgetProfile.members : [];
  const voiceInfo = collectVoiceInfo(widgetChannels, widgetMembers, fallback);
  const inviteEntryChannel = formatChannelName(inviteProfile?.channel?.name, "");
  const liveTextChannel = formatChannelName(
    insightsProfile?.mostActiveTextChannel ??
      insightsProfile?.topTextChannel ??
      insightsProfile?.most_messages_channel,
    ""
  );
  const liveTextChannelMessages = toNumber(
    insightsProfile?.mostActiveTextChannelMessages ??
      insightsProfile?.topTextChannelMessages ??
      insightsProfile?.most_messages_count,
    0
  );
  const hasLiveTextInsights = Boolean(liveTextChannel && liveTextChannelMessages > 0);
  const description = inviteGuild?.description ?? widgetProfile?.description ?? fallback.description;
  const iconHash = inviteGuild?.icon ?? inviteServerProfile?.icon_hash;
  const bannerHash = inviteGuild?.banner ?? inviteServerProfile?.custom_banner_hash;

  return {
    members: toNumber(inviteProfile?.approximate_member_count, fallback.members),
    online: toNumber(
      inviteProfile?.approximate_presence_count ?? widgetProfile?.presence_count,
      fallback.online
    ),
    boosts: toNumber(
      inviteGuild?.premium_subscription_count ?? inviteServerProfile?.premium_subscription_count,
      fallback.boosts
    ),
    channels: widgetChannels.length || fallback.channels,
    premiumTier: toNumber(inviteGuild?.premium_tier ?? inviteServerProfile?.premium_tier, fallback.premiumTier),
    serverName: inviteGuild?.name ?? widgetProfile?.name ?? fallback.serverName,
    description,
    entryChannel: inviteEntryChannel || extractEntryChannel(widgetChannels, fallback.entryChannel),
    topChannels: extractTopChannels(widgetChannels, fallback.topChannels),
    mostActiveTextChannel: hasLiveTextInsights ? liveTextChannel : fallback.mostActiveTextChannel,
    mostActiveTextChannelMessages: hasLiveTextInsights
      ? liveTextChannelMessages
      : fallback.mostActiveTextChannelMessages,
    hasLiveTextInsights,
    languages: collectLanguages(widgetChannels, fallback.languages),
    activeVoiceChannel: formatRoomName(voiceInfo.channelName, fallback.activeVoiceChannel),
    activeVoiceCount: voiceInfo.memberCount,
    topGame: extractTopGame(widgetMembers, fallback.topGame),
    traits: extractTraits(inviteServerProfile?.traits, description, fallback.traits),
    verificationEnabled: inferVerification(inviteGuild, widgetChannels, fallback.verificationEnabled),
    activityBins: buildActivityBins(
      inviteProfile?.liveliness?.msg_activity_bins ?? inviteServerProfile?.liveliness?.msg_activity_bins,
      fallback.activityBins
    ),
    onlineMembers: extractOnlineMembers(widgetMembers),
    visibleChannels: widgetChannels.map((channel) => formatChannelName(channel.name, channel.name)),
    serverIconUrl: buildDiscordAssetUrl("icons", DISCORD_CONFIG.guildId, iconHash, 256),
    serverBannerUrl: buildDiscordAssetUrl("banners", DISCORD_CONFIG.guildId, bannerHash, 1024)
  };
}

function buildDiscordAssetUrl(type, guildId, hash, size) {
  if (!guildId || !hash) {
    return "";
  }

  return `https://cdn.discordapp.com/${type}/${guildId}/${hash}.png?size=${size}`;
}

function extractEntryChannel(channels, fallbackValue) {
  if (!channels.length) {
    return fallbackValue;
  }

  const [firstChannel] = channels
    .slice()
    .sort((left, right) => (left.position ?? 0) - (right.position ?? 0));

  return formatChannelName(firstChannel?.name, fallbackValue);
}

function extractTopChannels(channels, fallbackValue) {
  if (!channels.length) {
    return fallbackValue;
  }

  return channels
    .slice()
    .sort((left, right) => (left.position ?? 0) - (right.position ?? 0))
    .slice(0, 4)
    .map((channel) => formatChannelName(channel.name, channel.name));
}

function collectLanguages(channels, fallbackValue) {
  if (!channels.length) {
    return fallbackValue;
  }

  const languageMatches = channels
    .map((channel) => channel.name || "")
    .filter((name) => /english|bulgar|bg|balkan|language|lang/i.test(name))
    .slice(0, 3)
    .map((name) => formatChannelName(name, name));

  return languageMatches.length ? languageMatches : fallbackValue;
}

function collectVoiceInfo(channels, members, fallback) {
  const memberCountsByChannel = new Map();

  members.forEach((member) => {
    if (!member.channel_id) {
      return;
    }

    memberCountsByChannel.set(
      member.channel_id,
      (memberCountsByChannel.get(member.channel_id) || 0) + 1
    );
  });

  let bestChannelName = fallback.activeVoiceChannel;
  let bestChannelCount = fallback.activeVoiceCount;

  memberCountsByChannel.forEach((count, channelId) => {
    if (count < bestChannelCount) {
      return;
    }

    const channel = channels.find((item) => item.id === channelId);
    bestChannelName = channel?.name || bestChannelName;
    bestChannelCount = count;
  });

  return {
    channelName: bestChannelName,
    memberCount: bestChannelCount
  };
}

function extractTopGame(members, fallbackValue) {
  const gameCounts = new Map();

  members.forEach((member) => {
    const gameName = member.game?.name || member.activity?.name || "";
    const username = member.username || "";

    if (!gameName || !isUsefulGameActivity(username, gameName)) {
      return;
    }

    gameCounts.set(gameName, (gameCounts.get(gameName) || 0) + 1);
  });

  const rankedGames = Array.from(gameCounts.entries()).sort(
    (left, right) => right[1] - left[1] || left[0].localeCompare(right[0])
  );

  return rankedGames[0]?.[0] || fallbackValue;
}

function extractTraits(traitsFromProfile, description, fallbackTraits) {
  if (Array.isArray(traitsFromProfile) && traitsFromProfile.length) {
    return traitsFromProfile
      .map((trait) => trait?.label)
      .filter(Boolean)
      .slice(0, 5);
  }

  return deriveTraits(description, fallbackTraits);
}

function deriveTraits(description, fallbackTraits) {
  if (!description) {
    return fallbackTraits;
  }

  const lower = description.toLowerCase();
  const traitPool = [
    { match: /game|gaming|raid|boss|squad/, label: "Gaming" },
    { match: /tech|setup|hardware|pc|discord/, label: "Tech" },
    { match: /community|people|friends/, label: "Community" },
    { match: /bulgar|bg/, label: "Bulgarian" },
    { match: /english|international/, label: "English" }
  ];

  const traits = traitPool
    .filter((item) => item.match.test(lower))
    .map((item) => item.label);

  return traits.length ? traits : fallbackTraits;
}

function inferVerification(inviteGuild, channels, fallbackValue) {
  if (typeof inviteGuild?.verification_level === "number") {
    return inviteGuild.verification_level > 0;
  }

  if (!channels.length) {
    return fallbackValue;
  }

  return channels.some((channel) => /verify|rules|welcome|start/i.test(channel.name || ""));
}

function buildActivityBins(rawBins, fallbackBins) {
  const source = Array.isArray(rawBins) && rawBins.length
    ? rawBins.map((value) => toNumber(value, 0))
    : fallbackBins;

  if (!Array.isArray(source) || !source.length) {
    return fallbackBins;
  }

  return source.slice(-24);
}

function extractOnlineMembers(members) {
  return members
    .filter((member) => !isBotLikeMember(member.username || ""))
    .slice(0, 8)
    .map((member) => ({
      username: member.username || "Member",
      avatarUrl: member.avatar_url || "",
      status: member.status || "online"
    }));
}

function isUsefulGameActivity(username, gameName) {
  return !(
    /bot|board|music|discordservers|bumpy|truth or dare|top\.gg|restream/i.test(username) ||
    /^\//.test(gameName) ||
    /help|servers|bump|rythm\.fm/i.test(gameName)
  );
}

function isBotLikeMember(username) {
  return /bot|gg|disboard|discordservers|top\.gg|music|restream|truth or dare/i.test(username);
}

function formatChannelName(name, fallbackValue) {
  if (!name) {
    return fallbackValue || "";
  }

  const cleaned = name
    .replace(/[|｜]/g, " ")
    .replace(/\s+/g, " ")
    .replace(/^[#]+/, "")
    .trim();

  if (!cleaned) {
    return fallbackValue || "";
  }

  return /^[#]/.test(cleaned) ? cleaned : `#${cleaned}`;
}

function formatRoomName(name, fallbackValue) {
  if (!name) {
    return fallbackValue || "";
  }

  return String(name)
    .replace(/[|｜]/g, " ")
    .replace(/\s+/g, " ")
    .trim() || fallbackValue || "";
}

function toNumber(value, fallbackValue) {
  const numericValue = Number(value);
  return Number.isFinite(numericValue) ? numericValue : fallbackValue;
}

function normalizeScheduledEvents(payload) {
  const sourceItems = Array.isArray(payload)
    ? payload
    : Array.isArray(payload?.events)
      ? payload.events
      : Array.isArray(payload?.items)
        ? payload.items
        : Array.isArray(payload?.data)
          ? payload.data
          : [];

  return sourceItems
    .map((item, index) => normalizeScheduledEvent(item, index))
    .filter(Boolean)
    .sort((left, right) => {
      const leftTime = left.startAt ? left.startAt.getTime() : Number.MAX_SAFE_INTEGER;
      const rightTime = right.startAt ? right.startAt.getTime() : Number.MAX_SAFE_INTEGER;
      return leftTime - rightTime;
    });
}

function normalizeScheduledEvent(item, index) {
  const title = item?.name ?? item?.title ?? item?.eventName ?? item?.summary;

  if (!title) {
    return null;
  }

  const startAt = parseDateValue(
    item?.scheduled_start_time ?? item?.scheduledStartTime ?? item?.startAt ?? item?.start_time
  );
  const endAt = parseDateValue(
    item?.scheduled_end_time ?? item?.scheduledEndTime ?? item?.endAt ?? item?.end_time
  );

  return {
    id: item?.id ?? `event-${index}`,
    title,
    description: item?.description ?? item?.details ?? "",
    location:
      item?.entity_metadata?.location ??
      item?.location ??
      item?.channel?.name ??
      item?.channel_name ??
      "",
    startAt,
    endAt,
    status: normalizeEventStatus(item?.status ?? item?.state),
    interestedCount: toNumber(
      item?.user_count ?? item?.interested_count ?? item?.participantCount,
      0
    )
  };
}

function normalizeEventStatus(rawStatus) {
  if (rawStatus === 2 || rawStatus === "active" || rawStatus === "ACTIVE") {
    return "active";
  }

  if (rawStatus === 3 || rawStatus === "completed" || rawStatus === "COMPLETED") {
    return "completed";
  }

  if (rawStatus === 4 || rawStatus === "cancelled" || rawStatus === "canceled" || rawStatus === "CANCELLED") {
    return "cancelled";
  }

  return "scheduled";
}

function normalizeNewsFeed(payload) {
  const sourceItems = Array.isArray(payload)
    ? payload
    : Array.isArray(payload?.messages)
      ? payload.messages
      : Array.isArray(payload?.items)
        ? payload.items
        : Array.isArray(payload?.data)
          ? payload.data
          : [];

  return sourceItems
    .map((item, index) => normalizeNewsItem(item, index))
    .filter(Boolean)
    .sort((left, right) => {
      const leftTime = left.postedAt ? left.postedAt.getTime() : 0;
      const rightTime = right.postedAt ? right.postedAt.getTime() : 0;
      return rightTime - leftTime;
     });
}

function normalizeRssNewsFeed(xmlText) {
  if (!xmlText) {
    return [];
  }

  const parser = new DOMParser();
  const xml = parser.parseFromString(xmlText, "text/xml");

  if (xml.querySelector("parsererror")) {
    throw new Error("RSS feed could not be parsed");
  }

  return Array.from(xml.querySelectorAll("channel > item"))
    .map((item, index) => normalizeRssNewsItem(item, index))
    .filter(Boolean)
    .sort((left, right) => {
      const leftTime = left.postedAt ? left.postedAt.getTime() : 0;
      const rightTime = right.postedAt ? right.postedAt.getTime() : 0;
      return rightTime - leftTime;
    });
}

function normalizeRssNewsItem(item, index) {
  const title = cleanFeedText(readXmlNodeText(item, "title"));
  const description = extractFeedExcerpt(readXmlNodeText(item, "description"));
  const body = stripSourceLink(description || title);
  const jumpUrl = readXmlNodeText(item, "link");
  const authorName =
    cleanFeedText(readXmlNodeText(item, "dc\\:creator")) ||
    cleanFeedText(readXmlNodeText(item, "creator")) ||
    DISCORD_CONFIG.rssFeedLabel;
  const postedAt = parseDateValue(readXmlNodeText(item, "pubDate"));
  const categories = Array.from(item.querySelectorAll("category"))
    .map((node) => cleanFeedText(node.textContent))
    .filter(Boolean);
  const categoryLabel = categories.find((value) => /новини|news/i.test(value)) || categories[0] || "Website post";

  if (!title && !body) {
    return null;
  }

  return {
    id: jumpUrl || `rss-news-${index}`,
    title: title || truncate(body, 72),
    body: body || "Latest publication from BG-GAMER.com.",
    authorName,
    authorAvatar: "",
    channelName: categoryLabel,
    postedAt,
    jumpUrl
  };
}

function normalizeNewsItem(item, index) {
  const rawContent = item?.content ?? item?.body ?? item?.description ?? item?.summary ?? "";
  const embedTitle = item?.embeds?.[0]?.title ?? "";
  const embedDescription = item?.embeds?.[0]?.description ?? "";
  const content = cleanDiscordText(rawContent || embedDescription);
  const title = cleanDiscordText(item?.title ?? embedTitle) || truncate(content, 72);

  if (!title && !content) {
    return null;
  }

  const channelName = formatChannelName(
    item?.channel_name ?? item?.channelName ?? item?.channel?.name,
    ""
  );
  const postedAt = parseDateValue(
    item?.timestamp ?? item?.created_at ?? item?.createdAt ?? item?.date
  );
  const jumpUrl = item?.jump_url ||
    item?.jumpUrl ||
    item?.url ||
    buildDiscordMessageUrl(
      item?.guild_id ?? item?.guildId ?? DISCORD_CONFIG.guildId,
      item?.channel_id ?? item?.channelId ?? DISCORD_CONFIG.newsChannelId,
      item?.id
    );

  return {
    id: item?.id ?? `news-${index}`,
    title,
    body: content || "Recent server announcement from the configured Discord news channel.",
    authorName:
      item?.author?.global_name ??
      item?.author?.username ??
      item?.authorName ??
      item?.username ??
      "BG-GAMER",
    authorAvatar: item?.author?.avatarUrl ?? item?.author?.avatar_url ?? item?.authorAvatar ?? "",
    channelName,
    postedAt,
    jumpUrl
  };
}

function cleanDiscordText(text) {
  return String(text || "")
    .replace(/<a?:\w+:\d+>/g, "")
    .replace(/<#(\d+)>/g, "#channel")
    .replace(/<@!?(\d+)>/g, "@member")
    .replace(/\*\*(.*?)\*\*/g, "$1")
    .replace(/\[(.*?)\]\((.*?)\)/g, "$1")
    .replace(/`{1,3}/g, "")
    .replace(/\s+/g, " ")
    .trim();
}

function cleanFeedText(text) {
  const html = String(text || "").trim();

  if (!html) {
    return "";
  }

  const template = document.createElement("template");
  template.innerHTML = html;
  return template.content.textContent.replace(/\s+/g, " ").trim();
}

function stripSourceLink(text) {
  return String(text || "")
    .replace(/\b(източник|source)\b.*$/i, "")
    .replace(/\s+/g, " ")
    .trim();
}

function readXmlNodeText(parent, selector) {
  return parent?.querySelector(selector)?.textContent?.trim() ?? "";
}

function extractFeedExcerpt(html) {
  const template = document.createElement("template");
  template.innerHTML = String(html || "");

  template.content.querySelectorAll("a").forEach((node) => node.remove());

  const paragraphs = Array.from(template.content.querySelectorAll("p"));
  const sourceParagraph = paragraphs.find((node) => /източник|source/i.test(node.textContent || ""));
  if (sourceParagraph) {
    sourceParagraph.remove();
  }

  const text = template.content.textContent.replace(/\s+/g, " ").trim();
  return text;
}

function buildDiscordMessageUrl(guildId, channelId, messageId) {
  if (!guildId || !channelId || !messageId) {
    return "";
  }

  return `https://discord.com/channels/${guildId}/${channelId}/${messageId}`;
}

function parseDateValue(value) {
  if (!value) {
    return null;
  }

  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function buildEventsMeta(events) {
  if (events === null) {
    return {
      state: "demo",
      label: "Events unavailable",
      source: "Discord events feed is unavailable right now"
    };
  }

  if (!events.length) {
    return {
      state: "partial",
      label: "No scheduled events",
      source: "Connected events proxy"
    };
  }

  return {
    state: "live",
    label: events.length === 1 ? "1 scheduled event" : `${events.length} scheduled events`,
    source: "Connected Discord events proxy"
  };
}

function buildNewsMeta(news) {
  if (news === null) {
    return {
      state: "demo",
      label: "Feed unavailable",
      source: "BG-GAMER RSS feed is unavailable right now"
    };
  }

  if (!news.length) {
    return {
      state: "partial",
      label: "Quiet feed",
      source: "Connected BG-GAMER RSS feed"
    };
  }

  return {
    state: "live",
    label: news.length === 1 ? "1 recent post" : `${news.length} recent posts`,
    source: "Connected BG-GAMER RSS feed"
  };
}

function applyDiscordStats(stats) {
  latestStats = stats;
  if (stats.meta?.state !== "demo") {
    cachedStats = stats;
  }

  document.body.dataset.syncState = "ready";

  updateStatsBadge(stats.meta);
  updateStatsMeta(stats.meta);
  updateHeroSection(stats);
  updateTicker(stats);
  updateServerNavigator(stats);
  updateActivityStage(stats);
  updateNewsFeed(stats);
  updateToolkitPanel(stats);
  updateIdentity(stats);
  updateJoinLobby(stats);
  updateCounterElements(stats);
}

function updateStatsBadge(meta) {
  const badge = document.getElementById("statsStatus");
  const badgeText = document.getElementById("statsStatusText");

  if (badge && meta?.state) {
    badge.dataset.state = meta.state;
  }

  if (badgeText) {
    badgeText.textContent = meta?.label ?? "Demo data";
  }

  setText("heroServerStatus", meta?.label ?? "Demo data");
  setText("ctaStatusText", meta?.label ?? "Demo data");
}

function updateStatsMeta(meta) {
  setText("statsSourceText", meta?.source ?? "No live source connected");
  setText(
    "statsUpdatedText",
    meta?.updatedAt ? `Updated ${timeFormatter.format(meta.updatedAt)}` : "Waiting for first sync"
  );
}

function updateHeroSection(stats) {
  setText("heroServerName", stats.serverName);
  setText("heroVoiceCount", numberFormatter.format(stats.activeVoiceCount));

  const roomLabel = stats.activeVoiceCount > 0 ? stats.activeVoiceChannel : `${stats.entryChannel} ready`;
  setText("heroVoiceRoom", truncate(roomLabel, 28));
  setText(
    "heroVoiceText",
    stats.activeVoiceCount > 0
      ? `${numberFormatter.format(stats.activeVoiceCount)} members are live in voice right now.`
      : "Voice rooms are open and waiting for the next session."
  );

  setText("heroProfileText", truncate(stats.description, 140));
  setText("heroChannelText", stats.topChannels.join(" · "));
  setText(
    "heroGameText",
    stats.topGame
      ? `${stats.topGame} is showing up in the current widget activity.`
      : "No dominant game signal is visible in the widget right now."
  );
  setText(
    "heroActivityText",
    `${numberFormatter.format(stats.online)} live • ${stats.activeVoiceCount > 0 ? `${numberFormatter.format(stats.activeVoiceCount)} in voice` : "voice ready"}`
  );
  setText(
    "heroBoostsText",
    `${numberFormatter.format(stats.boosts)} boosts • Tier ${stats.premiumTier}`
  );

  setText("heroProfileText", truncate(stats.description, 120));
  renderHeroChannelTags(stats.topChannels);
  setText("heroActivityText", `${numberFormatter.format(stats.online)} live`);
  setText(
    "heroMembersMeta",
    stats.activeVoiceCount > 0
      ? `${numberFormatter.format(stats.activeVoiceCount)} members are in voice right now.`
      : "Voice rooms are open and ready when the next squad forms."
  );
  setText("heroBoostsText", `${numberFormatter.format(stats.boosts)} boosts · Tier ${stats.premiumTier}`);

  renderAvatarStack(stats.onlineMembers);
  updatePresenceVisual(stats.online, stats.members);
  updateHeroBackdrop(stats.serverBannerUrl);
}

function updateHeroBackdrop(bannerUrl) {
  const backdrop = document.getElementById("heroBackdrop");

  if (!backdrop) {
    return;
  }

  backdrop.style.backgroundImage = bannerUrl
    ? `linear-gradient(145deg, rgba(5, 10, 18, 0.35), rgba(5, 10, 18, 0.88)), url("${bannerUrl}")`
    : "linear-gradient(145deg, rgba(5, 10, 18, 0.32), rgba(5, 10, 18, 0.88))";
}

function renderAvatarStack(members) {
  const stack = document.getElementById("heroAvatarStack");

  if (!stack) {
    return;
  }

  stack.replaceChildren();

  if (!members.length) {
    const placeholder = document.createElement("span");
    placeholder.className = "avatar-stack__placeholder";
    placeholder.textContent = "Public avatar stack will appear when the widget exposes live members.";
    stack.append(placeholder);
    return;
  }

  members.forEach((member) => {
    const item = document.createElement("span");
    item.className = "avatar-stack__item";
    item.title = member.username;

    if (member.avatarUrl) {
      const image = document.createElement("img");
      image.src = member.avatarUrl;
      image.alt = member.username;
      image.loading = "lazy";
      item.append(image);
    } else {
      item.textContent = member.username.slice(0, 1).toUpperCase();
    }

    stack.append(item);
  });
}

function renderHeroChannelTags(channels) {
  const container = document.getElementById("heroChannelTags");

  if (!container) {
    return;
  }

  container.replaceChildren();

  if (!Array.isArray(channels) || !channels.length) {
    const fallback = document.createElement("span");
    fallback.className = "lobby-feed__tag";
    fallback.textContent = "Channel sync pending";
    container.append(fallback);
    return;
  }

  channels.slice(0, 5).forEach((channel) => {
    const tag = document.createElement("span");
    tag.className = "lobby-feed__tag";
    tag.textContent = channel;
    container.append(tag);
  });
}

function updateTicker(stats) {
  setText(
    "tickerVoiceValue",
    stats.activeVoiceCount > 0
      ? `${numberFormatter.format(stats.activeVoiceCount)} in ${truncate(stats.activeVoiceChannel, 14)}`
      : "Lobby ready"
  );
  setText(
    "tickerEventValue",
    stats.events?.length
      ? truncate(stats.events[0].title, 28)
      : stats.news?.length
        ? truncate(stats.news[0].title, 28)
        : "Waiting for feed"
  );
  setText(
    "tickerEventValue",
    stats.news?.length
      ? truncate(stats.news[0].title, 28)
      : stats.events?.length
        ? truncate(stats.events[0].title, 28)
        : "Waiting for feed"
  );
  setText(
    "tickerTextChannelValue",
    stats.hasLiveTextInsights
      ? `${truncate(stats.mostActiveTextChannel, 18)} • ${numberFormatter.format(stats.mostActiveTextChannelMessages)}`
      : "Connect message proxy"
  );
}

function updateServerNavigator(stats) {
  const zones = buildZoneConfig(stats);
  const activeZone = zones[activeZoneId] || zones.welcome;

  setText("zonePreviewMeta", activeZone.meta);
  setText("zonePreviewTitle", activeZone.title);
  setText("zonePreviewLead", activeZone.lead);
  setText("zonePreviewStatOne", activeZone.statOne);
  setText("zonePreviewStatTwo", activeZone.statTwo);
  setText("zonePreviewStatThree", activeZone.statThree);
  setText("zonePreviewFoot", activeZone.foot);
  renderZoneTags(activeZone.tags);
}

function buildZoneConfig(stats) {
  const afterDarkChannel = stats.visibleChannels.find((channel) => /night|nsfw|late/i.test(channel));

  return {
    welcome: {
      meta: "Entry routing",
      title: "Welcome",
      lead: `${stats.entryChannel} is the visible invite landing point and the first orientation layer for new visitors.`,
      statOne: stats.entryChannel,
      statTwo: stats.verificationEnabled ? "Verification on" : "Open entry",
      statThree: `${numberFormatter.format(stats.members)} total members`,
      tags: [stats.entryChannel, "Invite landing", "First impression", "Rules and access"],
      foot: "This zone exists to turn curiosity into clean onboarding instead of dumping visitors into random chat."
    },
    gaming: {
      meta: "Game discovery",
      title: "Gaming",
      lead: stats.topGame
        ? `${stats.topGame} is part of the current public activity signal, while visible channels and role routing help members find the right squad lane.`
        : "Game discovery is driven by role routing, visible rooms, and whatever activity is currently bubbling through the widget feed.",
      statOne: stats.topGame || "No dominant game",
      statTwo: stats.topChannels[0] || "Visible gaming room",
      statThree: `${numberFormatter.format(stats.online)} players online`,
      tags: stats.topChannels,
      foot: "The goal here is quick game coordination, not a generic feature grid about gaming."
    },
    voice: {
      meta: "Voice presence",
      title: "Voice",
      lead: stats.activeVoiceCount > 0
        ? `${numberFormatter.format(stats.activeVoiceCount)} members are active in ${stats.activeVoiceChannel}, which makes the lobby feel alive before a visitor even joins.`
        : "The voice layer is ready even when the current public widget does not show an occupied room.",
      statOne: stats.activeVoiceChannel,
      statTwo: `${numberFormatter.format(stats.activeVoiceCount)} live now`,
      statThree: stats.topChannels.find((channel) => /hub|join|afk|stage|stream/i.test(channel)) || "Voice route available",
      tags: ["Gaming VC", "Social VC", "Join to create", "Stage / stream flow"],
      foot: "Voice is treated like a core part of the community product, not a hidden extra behind the text chat."
    },
    support: {
      meta: "Support flow",
      title: "Support",
      lead: "Support routing stays structured through verification, self-managed roles, and ticket-based moderation or troubleshooting when public chat is not the right place.",
      statOne: "/ticket",
      statTwo: "/roles",
      statThree: stats.verificationEnabled ? "Moderated entry" : "Open entry",
      tags: ["Troubleshooting", "Permission issues", "Role updates", "Private moderation"],
      foot: "This zone is about keeping problems solvable without letting the public lobby turn into staff-only traffic."
    },
    news: {
      meta: "Website updates and schedule",
      title: "Server News",
      lead: stats.news?.length
        ? `${stats.news[0].title} is the latest visible post from the BG-GAMER website feed.`
        : "The news rail is ready for BG-GAMER site updates, patch notes, highlights, and article drops from the RSS feed.",
      statOne: stats.news?.length ? stats.news[0].authorName : "Awaiting RSS feed",
      statTwo: stats.events?.length ? truncate(stats.events[0].title, 20) : "No scheduled event",
      statThree: "BG-GAMER RSS",
      tags: ["Website posts", "Patch notes", "Event reminders", "Highlight drops"],
      foot: "This keeps the page aligned with the real BG-GAMER publishing feed instead of inventing fake timeline content."
    },
    community: {
      meta: "Language and culture",
      title: "Community",
      lead: `BG-GAMER combines ${stats.languages.join(" + ")} spaces with gaming, tech help, clips, voice hangouts, and active moderation.`,
      statOne: stats.languages.join(" / "),
      statTwo: stats.traits.slice(0, 2).join(" + "),
      statThree: `${numberFormatter.format(stats.online)} present now`,
      tags: stats.traits,
      foot: "The community identity is bilingual, practical, and activity-first rather than corporate or overly polished."
    },
    afterdark: {
      meta: "Late-hours signal",
      title: "After Dark",
      lead: afterDarkChannel
        ? `${afterDarkChannel} hints at the server's late-night social side without changing the main onboarding flow.`
        : "Even when the public widget exposes only part of the structure, the tone still leaves room for late-night voice, memes, and casual hangouts.",
      statOne: afterDarkChannel || "Late-night hangouts",
      statTwo: stats.activeVoiceCount > 0 ? `${numberFormatter.format(stats.activeVoiceCount)} in voice` : "Quiet for now",
      statThree: stats.topGame || "Open social lane",
      tags: ["Clips", "Memes", "Watch parties", "Night sessions"],
      foot: "This is where the server feels less like a directory and more like a place people actually stay in after peak hours."
    }
  };
}

function renderZoneTags(tags) {
  const container = document.getElementById("zonePreviewTags");

  if (!container) {
    return;
  }

  container.replaceChildren();

  tags.filter(Boolean).slice(0, 6).forEach((tagText) => {
    const item = document.createElement("span");
    item.textContent = tagText;
    container.append(item);
  });
}

function updateActivityStage(stats) {
  animateMetricValue("chartMembersValue", stats.members);
  animateMetricValue("chartOnlineValue", stats.online);
  animateMetricValue("chartChannelsValue", stats.channels);
  animateMetricValue("chartBoostsValue", stats.boosts);
  animateMetricValue("chartVoiceValue", stats.activeVoiceCount);

  setText(
    "chartActivityRatio",
    `${stats.members > 0 ? Math.round((stats.online / stats.members) * 100) : 0}%`
  );
  setText(
    "chartRoomLabel",
    truncate(stats.activeVoiceCount > 0 ? stats.activeVoiceChannel : stats.entryChannel, 24)
  );

  if (stats.hasLiveTextInsights) {
    setText("chartTextChannelLabel", truncate(stats.mostActiveTextChannel, 26));
    setText("chartTextChannelMeta", `${numberFormatter.format(stats.mostActiveTextChannelMessages)} messages`);
  } else if (stats.meta?.state === "demo") {
    setText("chartTextChannelLabel", truncate(stats.mostActiveTextChannel, 26));
    setText("chartTextChannelMeta", `${numberFormatter.format(stats.mostActiveTextChannelMessages)} demo messages`);
  } else {
    setText("chartTextChannelLabel", "Awaiting proxy");
    setText("chartTextChannelMeta", "Connect /api/discord/message-stats");
  }

  updateActivityDock(stats);
  animateActivityChart(stats.activityBins);

  const peak = Math.max(...stats.activityBins, 0);
  const peakIndex = stats.activityBins.indexOf(peak);
  const textSignal = stats.hasLiveTextInsights
    ? `${stats.mostActiveTextChannel} leads with ${numberFormatter.format(stats.mostActiveTextChannelMessages)} messages.`
    : "Text-channel ranking appears when the message proxy is connected.";
  const voiceSignal = stats.activeVoiceCount > 0
    ? `${numberFormatter.format(stats.activeVoiceCount)} members are in ${stats.activeVoiceChannel}.`
    : `${numberFormatter.format(stats.online)} members are online with voice rooms ready.`;

  setText(
    "serverGraphCaption",
    `Message liveliness peaks at bin ${peakIndex + 1} with ${numberFormatter.format(peak)} activity points. ${voiceSignal} ${textSignal}`.trim()
  );
}

function updateActivityDock(stats) {
  if (document.getElementById("activityNewsTitle")) {
    if (stats.news?.length) {
      const latestPost = stats.news[0];
      setText("activityNewsTitle", latestPost.title);
      setText(
        "activityNewsMeta",
        [
          latestPost.channelName || latestPost.authorName || DISCORD_CONFIG.rssFeedLabel,
          latestPost.postedAt ? newsDateFormatter.format(latestPost.postedAt) : "",
          "BG-GAMER.com"
        ]
          .filter(Boolean)
          .join(" · ") || "Pulled from the connected BG-GAMER RSS feed."
      );
    } else if (stats.news === null) {
      setText("activityNewsTitle", "RSS feed unavailable");
      setText("activityNewsMeta", "The BG-GAMER.com feed did not respond on the last refresh.");
    } else {
      setText("activityNewsTitle", "No recent post visible");
      setText("activityNewsMeta", "The BG-GAMER RSS feed is connected, but there is no recent post to pin here yet.");
    }

    setText("activityGameTitle", stats.topGame || "No dominant game signal");
    setText(
      "activityGameMeta",
      stats.topGame
        ? `Live widget activity currently points to ${stats.topGame}.`
        : "The public widget does not currently expose a strong in-game activity signal."
    );
    return;
  }

  if (stats.events?.length) {
    const nextEvent = stats.events[0];
    setText("activityEventTitle", nextEvent.title);
    setText(
      "activityEventMeta",
      [
        nextEvent.startAt ? formatEventDate(nextEvent.startAt) : "",
        nextEvent.location ? nextEvent.location : "",
        nextEvent.interestedCount > 0 ? `${numberFormatter.format(nextEvent.interestedCount)} interested` : ""
      ]
        .filter(Boolean)
        .join(" • ") || "Scheduled through the connected Discord events proxy."
    );
  } else {
    setText("activityEventTitle", "No scheduled event visible");
    setText("activityEventMeta", "Connect the scheduled events proxy or create a new server event to populate this slot.");
  }

  setText("activityGameTitle", stats.topGame || "No dominant game signal");
  setText(
    "activityGameMeta",
    stats.topGame
      ? `${stats.topGame} is currently part of the widget activity feed.`
      : "The public widget does not currently expose a strong in-game activity signal."
  );
}

function animateActivityChart(values) {
  const target = Array.isArray(values) && values.length ? values.slice() : DISCORD_CONFIG.fallbackStats.activityBins.slice();

  if (reduceMotion) {
    serverChartState = target.slice();
    renderActivityChart(target);
    return;
  }

  if (!serverChartState.length || serverChartState.length !== target.length) {
    serverChartState = target.map(() => 0);
  }

  if (serverChartAnimationFrame) {
    window.cancelAnimationFrame(serverChartAnimationFrame);
  }

  const fromValues = serverChartState.slice();
  const duration = 1100;
  const start = performance.now();

  const step = (timestamp) => {
    const progress = Math.min((timestamp - start) / duration, 1);
    const easedProgress = 1 - Math.pow(1 - progress, 3);

    serverChartState = target.map((targetValue, index) => {
      const fromValue = fromValues[index] ?? 0;
      return fromValue + ((targetValue - fromValue) * easedProgress);
    });

    renderActivityChart(serverChartState);

    if (progress < 1) {
      serverChartAnimationFrame = window.requestAnimationFrame(step);
      return;
    }

    serverChartState = target.slice();
  };

  serverChartAnimationFrame = window.requestAnimationFrame(step);
}

function renderActivityChart(values) {
  const area = document.getElementById("serverChartArea");
  const line = document.getElementById("serverChartLine");
  const dotsGroup = document.getElementById("serverChartDots");
  const labelsGroup = document.getElementById("serverChartLabels");

  if (!area || !line || !dotsGroup || !labelsGroup) {
    return;
  }

  const width = 832;
  const left = 44;
  const top = 46;
  const bottom = 318;
  const height = bottom - top;
  const count = values.length;
  const maxValue = Math.max(...values, 1);
  const stepX = count > 1 ? width / (count - 1) : width;
  const points = values.map((value, index) => {
    const x = left + (index * stepX);
    const y = bottom - ((value / maxValue) * (height - 16));
    return { x, y, value };
  });

  line.setAttribute("d", buildChartLinePath(points));
  area.setAttribute("d", buildChartAreaPath(points, bottom));

  dotsGroup.replaceChildren();
  labelsGroup.replaceChildren();

  const labelIndexes = new Set([0, Math.floor(count / 4), Math.floor(count / 2), Math.floor((count * 3) / 4), count - 1]);

  points.forEach((point, index) => {
    const dot = document.createElementNS("http://www.w3.org/2000/svg", "circle");
    dot.setAttribute("class", "activity-chart__dot");
    dot.setAttribute("cx", String(point.x));
    dot.setAttribute("cy", String(point.y));
    dot.setAttribute("r", index === count - 1 ? "6" : "4");
    dotsGroup.append(dot);

    if (labelIndexes.has(index)) {
      const label = document.createElementNS("http://www.w3.org/2000/svg", "text");
      label.setAttribute("class", "activity-chart__label");
      label.setAttribute("x", String(point.x));
      label.setAttribute("y", String(bottom + 22));
      label.setAttribute("text-anchor", index === 0 ? "start" : index === count - 1 ? "end" : "middle");
      label.textContent = buildActivityLabel(index, count);
      labelsGroup.append(label);
    }
  });
}

function buildActivityLabel(index, count) {
  if (index === count - 1) {
    return "Now";
  }

  const hoursAgo = Math.max(1, count - 1 - index);
  return `-${hoursAgo}h`;
}

function buildChartLinePath(points) {
  if (!points.length) {
    return "";
  }

  return points.reduce((path, point, index) => (
    `${path}${index === 0 ? "M" : " L"}${point.x} ${point.y}`
  ), "");
}

function buildChartAreaPath(points, bottom) {
  if (!points.length) {
    return "";
  }

  const linePath = buildChartLinePath(points);
  const firstPoint = points[0];
  const lastPoint = points[points.length - 1];

  return `${linePath} L${lastPoint.x} ${bottom} L${firstPoint.x} ${bottom} Z`;
}

function updateNewsFeed(stats) {
  const badge = document.getElementById("newsFeedBadge");
  const feed = document.getElementById("newsFeed");

  if (badge) {
    badge.dataset.state = stats.newsMeta?.state ?? "demo";
    badge.textContent = stats.newsMeta?.label ?? "Connecting";
  }

  if (!feed) {
    return;
  }

  setText(
    "newsFeedIntro",
    stats.news === null
      ? "The BG-GAMER RSS feed is currently unavailable."
      : !stats.news.length
        ? "The BG-GAMER RSS feed is connected, but there are no recent news posts to show right now."
        : `This rail is connected to ${DISCORD_CONFIG.rssFeedLabel} and is rendering the latest published posts from the RSS feed.`
  );

  const items = buildNewsItems(stats);
  feed.replaceChildren();
  items.forEach((item) => {
    feed.append(createNewsItemElement(item));
  });
}

function buildNewsItems(stats) {
  if (stats.news === null) {
    return [
      {
        metaLabel: "Feed unavailable",
        title: "BG-GAMER RSS feed is not reachable",
        body: "Once the website feed responds again, this rail will render the latest published posts automatically.",
        postedAt: null,
        jumpUrl: ""
      }
    ];
  }

  if (!stats.news.length) {
    return [
      {
        metaLabel: "Quiet feed",
        title: "No recent posts in the BG-GAMER feed",
        body: "Once a new article is published on the site, this rail will render it automatically from the RSS feed.",
        postedAt: null,
        jumpUrl: ""
      }
    ];
  }

  return stats.news.slice(0, 4).map((item) => ({
    metaLabel: item.channelName || item.authorName,
    title: item.title,
    body: truncate(item.body, 180),
    postedAt: item.postedAt,
    jumpUrl: item.jumpUrl
  }));
}

function createNewsItemElement(item) {
  const article = document.createElement("article");
  article.className = "news-item";

  const meta = document.createElement("span");
  meta.className = "news-item__meta";
  meta.textContent = item.metaLabel;
  article.append(meta);

  const title = document.createElement("strong");
  title.textContent = item.title;
  article.append(title);

  const body = document.createElement("p");
  body.textContent = item.body;
  article.append(body);

  if (item.postedAt || item.jumpUrl) {
    const footer = document.createElement("div");
    footer.className = "news-item__footer";

    if (item.postedAt) {
      const time = document.createElement("time");
      time.textContent = newsDateFormatter.format(item.postedAt);
      footer.append(time);
    }

    if (item.jumpUrl) {
      const link = document.createElement("a");
      link.href = item.jumpUrl;
      link.target = "_blank";
      link.rel = "noopener noreferrer";
      link.textContent = "Open article";
      footer.append(link);
    }

    article.append(footer);
  }

  return article;
}

function updateToolkitPanel(stats) {
  setText(
    "toolkitIntro",
    `${stats.serverName} currently exposes ${numberFormatter.format(stats.channels)} visible widget rooms, ${stats.verificationEnabled ? "a moderated entry gate" : "an open public entry"}, and a live voice signal when members are active.`
  );
  setText("toolkitEntryPoint", stats.entryChannel);
  setText("toolkitLanguages", stats.languages.join(" / "));
  setText(
    "toolkitStructure",
    `${stats.topChannels.slice(0, 3).join(" • ")}`
  );
}

function updateIdentity(stats) {
  setText(
    "identityPulse",
    stats.hasLiveTextInsights
      ? `${stats.mostActiveTextChannel} currently leads the message flow with ${numberFormatter.format(stats.mostActiveTextChannelMessages)} messages from the connected proxy.`
      : "Live Discord data remains central to the page, and the message-proxy slot is ready for the real text-channel ranking."
  );
}

function updateJoinLobby(stats) {
  setText("ctaInviteText", DISCORD_CONFIG.inviteUrl);
  setText("ctaStatusText", stats.meta?.label ?? "Demo data");
}

function updateCounterElements(stats) {
  document.querySelectorAll("[data-counter]").forEach((element) => {
    const key = element.dataset.statKey;
    const value = stats[key];

    if (!Number.isFinite(value)) {
      return;
    }

    if (reduceMotion || element.dataset.inView !== "false") {
      animateCounter(element, value);
      return;
    }

    element.textContent = numberFormatter.format(value);
    element.dataset.displayValue = String(value);
  });
}

function animateMetricValue(elementId, targetValue) {
  const element = document.getElementById(elementId);

  if (!element) {
    return;
  }

  if (reduceMotion) {
    element.textContent = numberFormatter.format(targetValue);
    element.dataset.renderedValue = String(targetValue);
    return;
  }

  const fromValue = Number(element.dataset.renderedValue || 0);
  const duration = 850;
  const start = performance.now();

  const step = (timestamp) => {
    const progress = Math.min((timestamp - start) / duration, 1);
    const easedProgress = 1 - Math.pow(1 - progress, 3);
    const currentValue = Math.round(fromValue + ((targetValue - fromValue) * easedProgress));

    element.textContent = numberFormatter.format(currentValue);

    if (progress < 1) {
      window.requestAnimationFrame(step);
      return;
    }

    element.dataset.renderedValue = String(targetValue);
  };

  window.requestAnimationFrame(step);
}

function animateCounter(element, target) {
  if (!Number.isFinite(target)) {
    return;
  }

  const fromValue = Number(element.dataset.displayValue || 0);

  if (reduceMotion) {
    element.textContent = numberFormatter.format(target);
    element.dataset.displayValue = String(target);
    return;
  }

  const duration = 1000;
  const start = performance.now();

  const step = (timestamp) => {
    const progress = Math.min((timestamp - start) / duration, 1);
    const easedProgress = 1 - Math.pow(1 - progress, 3);
    const currentValue = Math.round(fromValue + ((target - fromValue) * easedProgress));

    element.textContent = numberFormatter.format(currentValue);

    if (progress < 1) {
      window.requestAnimationFrame(step);
      return;
    }

    element.dataset.displayValue = String(target);
  };

  window.requestAnimationFrame(step);
}

function initServerNavigator() {
  document.querySelectorAll(".server-zone").forEach((button) => {
    button.addEventListener("click", () => {
      activeZoneId = button.dataset.zoneId || "welcome";
      syncServerZoneSelection();
      if (latestStats) {
        updateServerNavigator(latestStats);
      }
    });

    button.addEventListener("mouseenter", () => {
      if (window.matchMedia("(pointer: fine)").matches) {
        activeZoneId = button.dataset.zoneId || "welcome";
        syncServerZoneSelection();
        if (latestStats) {
          updateServerNavigator(latestStats);
        }
      }
    });
  });
}

function syncServerZoneSelection() {
  document.querySelectorAll(".server-zone").forEach((button) => {
    const selected = button.dataset.zoneId === activeZoneId;
    button.classList.toggle("is-active", selected);
    button.setAttribute("aria-selected", selected ? "true" : "false");
  });
}

function initHeroParallax() {
  if (reduceMotion) {
    return;
  }

  const hero = document.querySelector(".hero-shell");
  const layers = document.querySelectorAll("[data-parallax-layer]");

  if (!hero || !layers.length) {
    return;
  }

  hero.addEventListener("mousemove", (event) => {
    const rect = hero.getBoundingClientRect();
    const offsetX = ((event.clientX - rect.left) / rect.width) - 0.5;
    const offsetY = ((event.clientY - rect.top) / rect.height) - 0.5;

    layers.forEach((layer) => {
      const depth = layer.dataset.parallaxLayer === "lobby" ? 12 : 7;
      layer.style.transform = `translate3d(${offsetX * depth}px, ${offsetY * depth}px, 0)`;
    });
  });

  hero.addEventListener("mouseleave", () => {
    layers.forEach((layer) => {
      layer.style.transform = "";
    });
  });
}

function initRevealObserver() {
  const revealElements = document.querySelectorAll(".reveal");

  if (reduceMotion || !("IntersectionObserver" in window)) {
    revealElements.forEach((element) => element.classList.add("is-visible"));
    return;
  }

  const revealObserver = new IntersectionObserver(
    (entries, observer) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) {
          return;
        }

        entry.target.classList.add("is-visible");
        observer.unobserve(entry.target);
      });
    },
    {
      threshold: 0.14,
      rootMargin: "0px 0px -40px 0px"
    }
  );

  revealElements.forEach((element) => revealObserver.observe(element));
  revealVisibleElements();
  window.requestAnimationFrame(revealVisibleElements);
  window.setTimeout(revealVisibleElements, 250);
  window.addEventListener("scroll", revealVisibleElements, { passive: true });
  window.addEventListener("hashchange", () => window.setTimeout(revealVisibleElements, 60));
}

function revealVisibleElements() {
  document.querySelectorAll(".reveal:not(.is-visible)").forEach((element) => {
    const rect = element.getBoundingClientRect();
    const isVisible = rect.top < (window.innerHeight * 0.9) && rect.bottom > (window.innerHeight * 0.12);

    if (isVisible) {
      element.classList.add("is-visible");
    }
  });
}

function initCounterObserver() {
  const counters = document.querySelectorAll("[data-counter]");

  if (reduceMotion || !("IntersectionObserver" in window)) {
    counters.forEach((counter) => {
      counter.dataset.inView = "true";
    });
    return;
  }

  const counterObserver = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) {
          return;
        }

        entry.target.dataset.inView = "true";

        if (latestStats) {
          const key = entry.target.dataset.statKey;
          const value = latestStats[key];

          if (Number.isFinite(value)) {
            animateCounter(entry.target, value);
          }
        }
      });
    },
    {
      threshold: 0.35
    }
  );

  counters.forEach((counter) => {
    counter.dataset.inView = "false";
    counterObserver.observe(counter);
  });
}

function updatePresenceVisual(online, members) {
  const visual = document.querySelector("[data-presence-visual]");

  if (!visual) {
    return;
  }

  const ratio = members > 0 ? online / members : 0;
  const baseHeight = clamp(26 + (ratio * 72), 24, 92);
  const offsets = [-14, -6, 8, 16, 3, 12, -10];

  Array.from(visual.children).forEach((bar, index) => {
    const height = clamp(baseHeight + offsets[index % offsets.length], 18, 96);
    bar.style.height = `${height}%`;
  });
}

function formatEventDate(dateValue) {
  return eventDateFormatter.format(dateValue);
}

function truncate(text, maxLength) {
  if (!text || text.length <= maxLength) {
    return text || "";
  }

  return `${text.slice(0, maxLength - 1).trimEnd()}...`;
}

function clamp(value, min, max) {
  return Math.min(Math.max(value, min), max);
}

function setText(id, value) {
  const element = document.getElementById(id);

  if (element) {
    element.textContent = value;
  }
}
