import { createServer } from "node:http";
import { URL } from "node:url";

const DEFAULT_GUILD_ID = "114667416247599110";
const DEFAULT_NEWS_CHANNEL_ID = "506928759509745664";
const DISCORD_API_BASE = "https://discord.com/api/v10";

const config = {
  port: toInt(process.env.PORT, 8787),
  guildId: process.env.DISCORD_GUILD_ID || DEFAULT_GUILD_ID,
  newsChannelId: process.env.DISCORD_NEWS_CHANNEL_ID || DEFAULT_NEWS_CHANNEL_ID,
  botToken: process.env.DISCORD_BOT_TOKEN || "",
  allowedOrigin: process.env.ALLOWED_ORIGIN || "*",
  newsLimit: clampInt(process.env.NEWS_LIMIT, 6, 1, 20),
  eventsLimit: clampInt(process.env.EVENTS_LIMIT, 4, 1, 20),
  messageWindow: clampInt(process.env.MESSAGE_WINDOW, 100, 10, 100),
  textChannelLimit: clampInt(process.env.TEXT_CHANNEL_LIMIT, 12, 1, 30),
  messageStatsChannelIds: splitList(process.env.MESSAGE_STATS_CHANNEL_IDS)
};

const cache = new Map();

const server = createServer(async (req, res) => {
  try {
    applyCorsHeaders(res);

    if (req.method === "OPTIONS") {
      res.writeHead(204);
      res.end();
      return;
    }

    const requestUrl = new URL(req.url, `http://${req.headers.host}`);

    if (requestUrl.pathname === "/api/discord/health") {
      return sendJson(res, 200, {
        ok: true,
        guildId: config.guildId,
        newsChannelId: config.newsChannelId,
        hasBotToken: Boolean(config.botToken)
      });
    }

    if (!config.botToken) {
      return sendJson(res, 500, {
        error: "Missing DISCORD_BOT_TOKEN environment variable."
      });
    }

    if (requestUrl.pathname === "/api/discord/server-news") {
      const channelId = requestUrl.searchParams.get("channelId") || config.newsChannelId;
      const limit = clampInt(requestUrl.searchParams.get("limit"), config.newsLimit, 1, 20);
      const items = await getServerNews(channelId, limit);
      return sendJson(res, 200, {
        channelId,
        items,
        fetchedAt: new Date().toISOString()
      });
    }

    if (requestUrl.pathname === "/api/discord/events") {
      const limit = clampInt(requestUrl.searchParams.get("limit"), config.eventsLimit, 1, 20);
      const events = await getScheduledEvents(limit);
      return sendJson(res, 200, {
        events,
        fetchedAt: new Date().toISOString()
      });
    }

    if (requestUrl.pathname === "/api/discord/message-stats") {
      const limit = clampInt(requestUrl.searchParams.get("limit"), config.messageWindow, 10, 100);
      const channelIds = splitList(requestUrl.searchParams.get("channelIds")) || config.messageStatsChannelIds;
      const stats = await getMessageStats(channelIds, limit);
      return sendJson(res, 200, stats);
    }

    return sendJson(res, 404, {
      error: "Route not found."
    });
  } catch (error) {
    return sendJson(res, 500, {
      error: error instanceof Error ? error.message : "Unknown error"
    });
  }
});

server.listen(config.port, () => {
  console.log(`Discord proxy listening on http://localhost:${config.port}`);
});

function applyCorsHeaders(res) {
  res.setHeader("Access-Control-Allow-Origin", config.allowedOrigin);
  res.setHeader("Access-Control-Allow-Methods", "GET, OPTIONS");
  res.setHeader("Access-Control-Allow-Headers", "Content-Type, Authorization");
  res.setHeader("Content-Type", "application/json; charset=utf-8");
}

function sendJson(res, statusCode, payload) {
  res.writeHead(statusCode);
  res.end(JSON.stringify(payload));
}

async function getScheduledEvents(limit) {
  return withCache(`events:${limit}`, 30_000, async () => {
    const events = await discordFetch(
      `/guilds/${encodeURIComponent(config.guildId)}/scheduled-events?with_user_count=true`
    );

    return Array.isArray(events) ? events.slice(0, limit) : [];
  });
}

async function getServerNews(channelId, limit) {
  return withCache(`news:${channelId}:${limit}`, 15_000, async () => {
    const [messages, channels] = await Promise.all([
      discordFetch(`/channels/${encodeURIComponent(channelId)}/messages?limit=${limit}`),
      getGuildChannels()
    ]);
    const channelMap = new Map(
      channels.map((channel) => [channel.id, channel.name])
    );

    return Array.isArray(messages)
      ? messages.map((message) => normalizeMessage(message, channelMap.get(channelId)))
      : [];
  });
}

async function getMessageStats(requestedChannelIds, perChannelLimit) {
  return withCache(
    `message-stats:${requestedChannelIds.join(",")}:${perChannelLimit}`,
    25_000,
    async () => {
      const channels = await getGuildChannels();
      const availableChannels = channels
        .filter((channel) => channel.type === 0 || channel.type === 5)
        .sort((left, right) => (left.position ?? 0) - (right.position ?? 0));

      const targetChannelIds = requestedChannelIds.length
        ? requestedChannelIds
        : availableChannels.slice(0, config.textChannelLimit).map((channel) => channel.id);

      const channelLookup = new Map(
        availableChannels.map((channel) => [channel.id, channel.name])
      );

      const messageResults = await Promise.allSettled(
        targetChannelIds.map((channelId) => (
          discordFetch(`/channels/${encodeURIComponent(channelId)}/messages?limit=${perChannelLimit}`)
        ))
      );

      const rankedChannels = messageResults
        .map((result, index) => {
          if (result.status !== "fulfilled" || !Array.isArray(result.value)) {
            return null;
          }

          return {
            id: targetChannelIds[index],
            name: formatChannelName(channelLookup.get(targetChannelIds[index]) || targetChannelIds[index]),
            messages: result.value.filter((message) => !isBotMessage(message)).length
          };
        })
        .filter(Boolean)
        .sort((left, right) => right.messages - left.messages);

      const topChannel = rankedChannels[0] ?? null;

      return {
        mostActiveTextChannel: topChannel?.name ?? "",
        mostActiveTextChannelMessages: topChannel?.messages ?? 0,
        topTextChannels: rankedChannels.slice(0, 5),
        sampledChannels: rankedChannels.length,
        perChannelLimit,
        generatedAt: new Date().toISOString()
      };
    }
  );
}

async function getGuildChannels() {
  return withCache("guild-channels", 60_000, async () => {
    const channels = await discordFetch(`/guilds/${encodeURIComponent(config.guildId)}/channels`);
    return Array.isArray(channels) ? channels : [];
  });
}

async function discordFetch(pathname) {
  const response = await fetch(`${DISCORD_API_BASE}${pathname}`, {
    headers: {
      Authorization: `Bot ${config.botToken}`,
      Accept: "application/json",
      "User-Agent": "bg-gamer-discord-proxy/1.0"
    }
  });

  if (!response.ok) {
    const errorBody = await response.text();
    throw new Error(`Discord API ${response.status}: ${errorBody || response.statusText}`);
  }

  return response.json();
}

async function withCache(key, ttlMs, loader) {
  const cached = cache.get(key);
  const now = Date.now();

  if (cached && cached.expiresAt > now) {
    return cached.value;
  }

  const value = await loader();
  cache.set(key, {
    value,
    expiresAt: now + ttlMs
  });
  return value;
}

function normalizeMessage(message, channelName) {
  const content = cleanDiscordText(
    message?.content ||
      message?.embeds?.[0]?.description ||
      ""
  );
  const title = cleanDiscordText(message?.embeds?.[0]?.title || "") || truncate(content, 72) || "New server post";
  const author = message?.author ?? {};

  return {
    id: message?.id,
    title,
    content,
    author: {
      username: author?.global_name || author?.username || "BG-GAMER",
      avatar_url: buildUserAvatarUrl(author)
    },
    channel_id: message?.channel_id,
    channel_name: channelName || "",
    created_at: message?.timestamp,
    jump_url: buildDiscordMessageUrl(config.guildId, message?.channel_id, message?.id),
    embeds: Array.isArray(message?.embeds)
      ? message.embeds.slice(0, 1).map((embed) => ({
          title: embed?.title || "",
          description: cleanDiscordText(embed?.description || "")
        }))
      : []
  };
}

function buildUserAvatarUrl(author) {
  if (!author?.id) {
    return "";
  }

  if (author.avatar) {
    return `https://cdn.discordapp.com/avatars/${author.id}/${author.avatar}.png?size=128`;
  }

  return "";
}

function buildDiscordMessageUrl(guildId, channelId, messageId) {
  if (!guildId || !channelId || !messageId) {
    return "";
  }

  return `https://discord.com/channels/${guildId}/${channelId}/${messageId}`;
}

function isBotMessage(message) {
  return Boolean(message?.author?.bot) || /bot|disboard|top\.gg|restream/i.test(message?.author?.username || "");
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

function formatChannelName(name) {
  const cleaned = String(name || "")
    .replace(/[|｜]/g, " ")
    .replace(/\s+/g, " ")
    .replace(/^[#]+/, "")
    .trim();

  return cleaned ? `#${cleaned}` : "";
}

function truncate(text, maxLength) {
  if (!text || text.length <= maxLength) {
    return text || "";
  }

  return `${text.slice(0, maxLength - 1).trimEnd()}...`;
}

function splitList(value) {
  if (!value) {
    return [];
  }

  return String(value)
    .split(",")
    .map((item) => item.trim())
    .filter(Boolean);
}

function toInt(value, fallbackValue) {
  const parsed = Number.parseInt(String(value ?? ""), 10);
  return Number.isFinite(parsed) ? parsed : fallbackValue;
}

function clampInt(value, fallbackValue, min, max) {
  const parsed = toInt(value, fallbackValue);
  return Math.min(Math.max(parsed, min), max);
}
