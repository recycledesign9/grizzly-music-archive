<p align="center">
  <img src="docs/logo.jpg" width="400" alt="Grizzly Music Archive">
</p>

# 🐻 Grizzly Music Archive

![GitHub release](https://img.shields.io/github/v/release/recycledesign9/grizzly-music-archive?display_name=tag)
![License](https://img.shields.io/github/license/recycledesign9/grizzly-music-archive)
![Docker](https://img.shields.io/badge/docker-compose-blue)
![PHP](https://img.shields.io/badge/PHP-8.1+-777bb4)
![MySQL](https://img.shields.io/badge/MySQL-8.0+-4479A1)
![Self-hosted](https://img.shields.io/badge/self--hosted-ready-success)

> A self-hosted web application for cataloguing your personal vinyl, CD and cassette collection.
> PHP 8.1 · MySQL 8 · Bootstrap 5 · Vanilla JS — no frameworks, no dependencies to install.

---

## ✨ Features

- **Full CRUD** for albums, artists, genres and labels
- **Cover art** — automatic retrieval via MusicBrainz / Last.fm / Discogs, or manual upload
- **Tracklist** — automatic from MusicBrainz / Last.fm / Discogs or manual entry
- **Audio player** — upload MP3/FLAC files and play them directly in the browser via an HTML5 sticky player
- **YouTube integration** — automatically search and cache YouTube videos for tracks, with in-page preview via lightbox
- **Playlists** — create playlists, drag-and-drop reorder (SortableJS)
- **Advanced search** — by artist, title, format, genre, year, label
- **Artist page** — all albums linked to a single artist
- **Dashboard** — collection statistics (total by format, recent additions)
- **Dark mode** support
- **Relocatable audio folder** — store audio files outside the web root via the Settings page
- **Bulk MP3 upload** with automatic track matching (3-pass: track number + Levenshtein similarity)

---

## 🚀 Quick Start with Docker

### Prerequisites

- [Docker](https://docs.docker.com/get-docker/) and [Docker Compose](https://docs.docker.com/compose/install/) installed
- Ports `8080` (or your chosen `APP_PORT`) and `3306` available

### 1 — Clone the repository

```bash
git clone https://github.com/recycledesign9/grizzly-music-archive.git
cd grizzly-music-archive
```

### 2 — Configure environment

Copy the example environment file:

```bash
cp .env.example .env
```

Open `.env` and configure the application URL and exposed port according to where you are running Grizzly Music Archive.

For a standard installation, you usually only need to change:

```dotenv
BASE_URL=http://localhost:8080
APP_PORT=8080
```

If you access the app from multiple hostnames, through a reverse proxy, or both locally and publicly, also configure:

```dotenv
ALLOWED_HOSTS=localhost,localhost:8080,127.0.0.1,127.0.0.1:8080
```

### Basic scenarios

| Scenario                              | `BASE_URL`                     | `APP_PORT` | `ALLOWED_HOSTS`                                          |
| ------------------------------------- | ------------------------------ | ---------: | -------------------------------------------------------- |
| Local machine (Mac / Windows / Linux) | `http://localhost:8080`        |     `8080` | `localhost,localhost:8080,127.0.0.1,127.0.0.1:8080`      |
| Home or LAN server (IP address)       | `http://192.168.1.x:8080`      |     `8080` | `192.168.1.x:8080`                                       |
| Server with domain name               | `https://music.yourdomain.com` |     `8080` | `music.yourdomain.com`                                   |
| Reverse proxy + local access          | `https://music.yourdomain.com` |     `8080` | `music.yourdomain.com,192.168.1.x:8080,music.local:8080` |

### Variable meaning

> **`BASE_URL`:** canonical public URL of the application, with no trailing slash.
> It is used as the fallback URL when the current request host is not explicitly allowed.

> **`APP_PORT`:** port Docker exposes on your host machine.
> Change it if `8080` is already in use, for example `APP_PORT=8082`.

> **`ALLOWED_HOSTS`:** comma-separated list of hostnames or host:port combinations that are allowed to serve the app dynamically.
> This is useful when the same installation is reachable from more than one address, for example localhost, a LAN IP, a local hostname, or a reverse proxy domain.

> **Never edit `docker-compose.yml` for local settings.**
> Put your installation-specific values in `.env`.

Example — LAN server on port `9082`:

```dotenv
BASE_URL=http://192.168.1.x:9082
APP_PORT=9082
ALLOWED_HOSTS=192.168.1.x:9082
```

Example — local machine, default setup:

```dotenv
BASE_URL=http://localhost:8080
APP_PORT=8080
ALLOWED_HOSTS=localhost,localhost:8080,127.0.0.1,127.0.0.1:8080
```

Example — reverse proxy with public domain and LAN access:

```dotenv
BASE_URL=https://music.yourdomain.com
APP_PORT=8080
ALLOWED_HOSTS=music.yourdomain.com,192.168.1.x:8080,grizzly.local:8080
```

When Grizzly Music Archive is behind a reverse proxy such as Nginx Proxy Manager, SSL should normally terminate at the proxy. In that case, the app container can continue to run over HTTP internally.

### 3 — Start

```bash
docker compose up -d
```

Docker will:

1. Build the PHP + Apache image
2. Start MySQL and wait until it is healthy
3. Automatically import the schema (`docker/db/01_schema.sql`) and demo data (`docker/db/02_seed.sql`)
4. Serve the app at the address configured in `BASE_URL`

> **First startup** takes ~30–60 s while MySQL initialises. The app container waits for the database health check before starting.

### 4 — Open the app

Open your browser at the `BASE_URL` you set in `.env`, for example:

```text
http://localhost:8080
```

The archive starts pre-loaded with 12 demo albums (Beatles, Pink Floyd, Radiohead, Nirvana…).

To start **completely empty**, comment out the seed line in `docker-compose.yml`:

```yaml
# - ./docker/db/02_seed.sql:/docker-entrypoint-initdb.d/02_seed.sql:ro
```

Then run:

```bash
docker compose down -v
docker compose up -d
```

This deletes the existing database volume and rebuilds the database from scratch.

### Stop / restart

```bash
docker compose down        # stop (data is preserved in volumes)
docker compose down -v     # stop AND delete all data (full reset)
docker compose up -d       # start again
```

---

## 🔑 API Keys (optional)

**No API keys are required.** The app works fully out of the box thanks to **MusicBrainz**, a free and open music database that provides automatic cover art and tracklist retrieval with no registration or key needed.

Simply add an album, click **Recupera automaticamente** and Grizzly will fetch cover and tracklist from MusicBrainz automatically.

Last.fm and Discogs are used as **additional fallback sources** when MusicBrainz does not find a match. YouTube integration requires a key to enable track search and in-page preview.

| Feature               | Service             | Variable          | Notes                                                                        |
| --------------------- | ------------------- | ----------------- | ---------------------------------------------------------------------------- |
| Cover art + tracklist | **MusicBrainz**     | —                 | ✅ No key required — works out of the box                                     |
| Cover art + tracklist | Last.fm             | `LASTFM_API_KEY`  | Optional fallback — [get key](https://www.last.fm/api/account/create)        |
| Cover art             | Discogs             | `DISCOGS_TOKEN`   | Optional fallback — [get token](https://www.discogs.com/settings/developers) |
| YouTube integration   | YouTube Data API v3 | `YOUTUBE_API_KEY` | Required for YouTube preview — [get key](https://console.cloud.google.com)   |

To enable optional services, add keys to your `.env` file:

```dotenv
LASTFM_API_KEY=your_key_here
DISCOGS_TOKEN=your_token_here
YOUTUBE_API_KEY=your_key_here
```

Then restart the app container:

```bash
docker compose restart app
```

### YouTube embed on LAN servers

If you run Grizzly Music Archive on a home server accessed via a raw private IP address, for example:

```text
http://192.168.1.x:8082
```

YouTube may refuse to embed certain videos and show a "Video unavailable" message. This can happen because some embeds do not behave reliably with raw private IP addresses as origins.

A practical fix is to map a local hostname to your server IP on every device that will use the app.

On **macOS / Linux**:

```bash
sudo nano /etc/hosts
```

Add a line like this:

```text
192.168.1.x    grizzly.local
```

On **Windows**:

Open Notepad as Administrator, then open:

```text
C:\Windows\System32\drivers\etc\hosts
```

Add:

```text
192.168.1.x    grizzly.local
```

Then configure `.env` on the server:

```dotenv
BASE_URL=http://grizzly.local:8082
APP_PORT=8082
ALLOWED_HOSTS=grizzly.local,grizzly.local:8082,192.168.1.x:8082
```

Restart:

```bash
docker compose down
docker compose up -d
```

You can use any local hostname you like, for example `grizzly.local`, `grizzly.archive`, or `archive.home`. The hostname must resolve to the server IP on every client that uses the app, and it should be included in `ALLOWED_HOSTS`.

---

## 🛠 Local Development (without Docker)

Requirements: PHP 8.1+, MySQL 8.0 or MariaDB 10.6+, Apache with `mod_rewrite`.

```bash
# 1. Import the database
mysql -u root -p -e "CREATE DATABASE grizzly_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p grizzly_db < docker/db/01_schema.sql
mysql -u root -p grizzly_db < docker/db/02_seed.sql

# 2. Configure environment
cp .env.example .env
# Edit .env:
#   DB_HOST=localhost
#   DB_PORT=3306   (or 8889 for MAMP)
#   BASE_URL=http://localhost:8888/grizzly-music-archive   (adjust to your setup)
#   ALLOWED_HOSTS=localhost,localhost:8888

# 3. Set Apache DocumentRoot to the project root directory
# 4. Enable mod_rewrite and AllowOverride All
```

For MAMP users: set `DB_PORT=8889` and adjust `BASE_URL` to match your MAMP virtual host.

---

## 📁 Project Structure

```text
grizzly-music-archive/
├── docker/
│   ├── apache/
│   │   └── vhost.conf           < Apache virtual host configuration
│   └── db/
│       ├── 01_schema.sql        < Database structure
│       └── 02_seed.sql          < Demo data (safe to publish)
├── public/
│   ├── uploads/
│   │   ├── covers/              < Cover images (gitignored)
│   │   └── audio/               < Audio files (gitignored)
│   ├── css/
│   ├── js/
│   └── img/
├── config/
│   ├── config.php               < Env-driven configuration
│   └── database.php             < PDO singleton
├── app/
│   ├── controllers/             < AlbumController, ArtistController, …
│   ├── models/                  < Album, Artist, Track, …
│   └── services/                < AlbumMetadataService, MediaPathResolver, …
├── views/                       < PHP view templates
├── api/                         < YouTube track API endpoint
├── docs/                        < Project images and assets
├── Dockerfile
├── docker-compose.yml
├── .env.example                 < Template — copy to .env
├── .gitignore
└── README.md
```

---

## 🗄 Database Schema

| Table             | Description                                           |
| ----------------- | ----------------------------------------------------- |
| `artists`         | Artist / band records                                 |
| `albums`          | Album metadata (title, year, condition, cover, MBID…) |
| `formats`         | Vinile / CD / Musicassetta / Digital                  |
| `genres`          | Genre taxonomy                                        |
| `labels`          | Record labels                                         |
| `tracks`          | Tracklists with duration and cached YouTube ID        |
| `audio_files`     | Uploaded audio files linked to albums/tracks          |
| `playlists`       | User-created playlists                                |
| `playlist_tracks` | Many-to-many: playlists ↔ tracks (with position)      |
| `settings`        | Key-value app settings (e.g. custom audio path)       |

---

## ⚙️ Configuration Reference

All configuration is via environment variables. See `.env.example` for the full list.

| Variable          | Default                                             | Description                                                         |
| ----------------- | --------------------------------------------------- | ------------------------------------------------------------------- |
| `BASE_URL`        | `http://localhost:8080`                             | Canonical public URL, with no trailing slash                        |
| `APP_PORT`        | `8080`                                              | Host port for the web server                                        |
| `ALLOWED_HOSTS`   | `localhost,localhost:8080,127.0.0.1,127.0.0.1:8080` | Comma-separated list of allowed hostnames or host:port combinations |
| `DB_NAME`         | `grizzly_db`                                        | Database name                                                       |
| `DB_USER`         | `grizzly`                                           | Database user                                                       |
| `DB_PASS`         | `grizzly_secret`                                    | Database password                                                   |
| `DB_ROOT_PASS`    | `root_secret_change_me`                             | MySQL root password                                                 |
| `DEBUG`           | `false`                                             | Show PHP errors (`true` only for development)                       |
| `LASTFM_API_KEY`  | *(empty)*                                           | Last.fm API key                                                     |
| `DISCOGS_TOKEN`   | *(empty)*                                           | Discogs personal access token                                       |
| `YOUTUBE_API_KEY` | *(empty)*                                           | YouTube Data API v3 key                                             |

---

## 🔒 Security Notes

- Never commit `.env` to version control
- Set `DEBUG=false` in any non-local environment
- The `public/uploads/` directory is served by Apache; audio files outside the web root (configurable via Settings) are streamed through PHP with proper authentication
- All database queries use PDO prepared statements
- File uploads are validated by MIME type and extension server-side

---

## 🤝 Contributing

Pull requests are welcome. For major changes please open an issue first.

1. Fork the repo
2. Create your branch: `git checkout -b feature/my-feature`
3. Commit your changes: `git commit -m 'Add my feature'`
4. Push: `git push origin feature/my-feature`
5. Open a Pull Request

---

## 📸 Screenshots

Grizzly Music Archive is designed to work as a clean desktop archive and as a responsive mobile catalogue. The interface includes dashboard statistics, album browsing, artist profiles, discography lookup, track playback and dark mode support.

### Desktop interface

<p align="center">
  <img src=".github/assets/screenshots/01-dashboard-desktop.jpg" alt="Grizzly Music Archive desktop dashboard" width="100%">
</p>

<p align="center">
  <strong>Dashboard</strong><br>
  Collection statistics, recently added albums, top artists and playlist overview.
</p>

---

<p align="center">
  <img src=".github/assets/screenshots/02-album-detail-dark-player.jpg" alt="Grizzly Music Archive album detail with tracklist and audio player" width="100%">
</p>

<p align="center">
  <strong>Album detail and player</strong><br>
  Album metadata, tracklist, MP3/FLAC upload, YouTube lookup and sticky HTML5 audio player.
</p>

---

| Archive view                                                                                                      | Artist profile                                                                                                                                            |
| ----------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| <img src=".github/assets/screenshots/03-archive-list.jpg" alt="Grizzly Music Archive archive table" width="100%"> | <img src=".github/assets/screenshots/04-artist-profile-discography.jpg" alt="Grizzly Music Archive artist profile and official discography" width="100%"> |
| Searchable album table with cover, artist, format, year, genre and actions.                                       | Artist biography, local albums, format statistics and official discography.                                                                               |

### Mobile interface

| Mobile dashboard                                                                                                                                   | Mobile artist page                                                                                                                         |
| -------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| <img src=".github/assets/screenshots/05-mobile-dashboard-player.jpg" alt="Grizzly Music Archive mobile dashboard with sticky player" width="100%"> | <img src=".github/assets/screenshots/06-mobile-artist-discography.jpg" alt="Grizzly Music Archive mobile artist discography" width="100%"> |
| Responsive dashboard with compact cards, recent albums and sticky player.                                                                          | Mobile artist archive with official discography and cover thumbnails.                                                                      |

---

## 📜 License

MIT — see [LICENSE](LICENSE) for details.
