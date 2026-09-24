const http = require("http");
const fs = require("fs");
const path = require("path");

const root = process.argv[2] || process.cwd();
const port = Number.parseInt(process.argv[3] || "4173", 10);

const mimeTypes = {
  ".css": "text/css; charset=utf-8",
  ".html": "text/html; charset=utf-8",
  ".jpeg": "image/jpeg",
  ".jpg": "image/jpeg",
  ".js": "application/javascript; charset=utf-8",
  ".php": "text/html; charset=utf-8",
  ".png": "image/png",
  ".svg": "image/svg+xml",
  ".webp": "image/webp"
};

const server = http.createServer((request, response) => {
  const requestUrl = new URL(request.url, "http://127.0.0.1");
  let pathname = decodeURIComponent(requestUrl.pathname);

  if (pathname === "/") {
    pathname = "/index.html";
  }

  const resolvedPath = path.normalize(path.join(root, pathname));

  if (!resolvedPath.startsWith(root)) {
    response.writeHead(403);
    response.end("Forbidden");
    return;
  }

  fs.stat(resolvedPath, (error, stats) => {
    if (error) {
      response.writeHead(404);
      response.end("Not found");
      return;
    }

    let targetPath = resolvedPath;

    if (stats.isDirectory()) {
      const candidates = ["index.php", "index.html"]
        .map((fileName) => path.join(resolvedPath, fileName))
        .filter((candidate) => fs.existsSync(candidate));

      if (!candidates.length) {
        response.writeHead(404);
        response.end("Not found");
        return;
      }

      [targetPath] = candidates;
    }

    const extension = path.extname(targetPath).toLowerCase();
    response.writeHead(200, {
      "Content-Type": mimeTypes[extension] || "application/octet-stream"
    });

    fs.createReadStream(targetPath).pipe(response);
  });
});

server.listen(port, "127.0.0.1", () => {
  console.log(`preview-server:http://127.0.0.1:${port}`);
});
