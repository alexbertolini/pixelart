# Pixel Art

A small browser pixel-art editor with a "replicate the code" game mode, written as a single PHP page. Inspired by [pixiliart.com](https://pixiliart.com).

Drawing is rendered locally with JavaScript on `<canvas>` for instant response, and the state is saved to the PHP session in the background. A password-protected **boss** can store a picture's pixel code in MySQL and get a six-digit id. Anyone can then enter that id to load the code, and has to recreate the picture on a blank canvas using only the code as a guide, while a progress gauge tracks how close they are.

## Features

**Drawing (everyone)**
- Resolution from 4×4 up to 100×100 pixels, with the artwork kept where it still fits when you resize
- Palette of the 25 most common colours plus a "no colour" eraser
- Pen tool: click or drag to paint; fast drags are filled in so there are no gaps (touch screens work too)
- Light-gray / dark-gray checkerboard for empty pixels
- Clear canvas button
- Autosave to the PHP session about 0.4 s after you stop drawing, restored on reload

**Game mode (everyone)**
- Enter a six-digit code in the **Code** panel to load a stored picture's pixel code
- The canvas is resized to the picture's resolution and left **blank**; the code appears in the pixel coding panel as "Code to replicate"
- A **Progress** gauge shows 0% on the blank canvas and reaches 100% only when every pixel matches the code
- The game survives a page reload

**Boss panel (password protected)**
- Unlock with the boss code to reveal the option toggles and the Save button; **Lock** hides them again (the toggle values stay in effect)
- Toggles:
  - **Grid marks** (default ON)
  - **Row/column numbers**, starting at 1:1 in the top-left corner (default OFF)
  - **Highlight row/column** under the pointer, drawn as edge outlines so the current pixel keeps its colour; the matching code line is highlighted too (default OFF)
  - **Pixel coding** (default OFF): a live code panel for whatever is on the canvas
- **Save** stores the current image's pixel code in MySQL and returns a random six-digit id

Switching **Pixel coding** on replaces the game's code with the live code of the canvas.

## Requirements

- PHP 7.4 or newer with the `pdo_mysql` extension and sessions enabled
- MySQL 5.7+ or MariaDB, with a database already created
- A modern browser (canvas, Pointer Events and `fetch`)

## Installation

1. Copy `index.php` and `ww_config.php` to a folder served by PHP.
2. Create an empty MySQL database (for example `pixelart`).
3. Edit `ww_config.php` (see [Configuration](#configuration)). **Change the boss code.**
4. Open the page. For a quick local test:

   ```bash
   php -S localhost:8000 index.php
   ```

   then visit <http://localhost:8000>.

The table is created automatically the first time something is saved.

## Configuration

All settings live in `ww_config.php`, which returns an array:

| Key | Default | Meaning |
|---|---|---|
| `db_host` | `127.0.0.1` | MySQL host |
| `db_port` | `3306` | MySQL port |
| `db_name` | `pixelart` | Database name (must already exist) |
| `db_user` | `root` | Database user |
| `db_pass` | *(empty)* | Database password |
| `db_charset` | `utf8mb4` | Connection charset |
| `db_table` | `pixel_art` | Table name (letters, digits and `_` only) |
| `boss_code` | `changeme` | Password for the boss panel |

If the file or a key is missing, `index.php` falls back to these defaults. Requesting `ww_config.php` directly returns a 403.

## Sample pictures

`sample_images.sql` creates the table (if needed) and inserts 20 ready-made 20×20 pictures, using `INSERT IGNORE` so it can be re-run safely. If you changed `db_table`, edit the table name in the file first.

```bash
mysql -u root -p pixelart < sample_images.sql
```

| Code | Picture | Code | Picture |
|---|---|---|---|
| 252721 | Heart | 398286 | Coffee |
| 125587 | Smiley | 604990 | Sword |
| 345649 | Pine tree | 841109 | Rainbow |
| 312126 | House | 224106 | Space invader |
| 325448 | Star | 634093 | Ice cream |
| 460123 | Apple | 163740 | Fish |
| 415346 | Sun | 111342 | Rocket |
| 327015 | Mushroom | 881392 | Ghost |
| 461443 | Flower | 470784 | Cherries |
| 587625 | Cat | 407552 | Crescent moon |

## The pixel code format

A picture is described by one line per row. Each line is a list of runs written `<count><colour>`, separated by spaces, and every line covers the full width of the canvas. Blank rows at the bottom are omitted; blank rows in the middle are kept.

```
5- 3f 4- 3f 5-      5 empty, 3 red, 4 empty, 3 red, 5 empty  (a 20-pixel row)
20-                 a completely empty row
```

Colours are single letters, `-` meaning no colour:

| | | | | |
|---|---|---|---|---|
| `a` Black `#000000` | `b` Dark gray `#595959` | `c` Gray `#a6a6a6` | `d` Light gray `#d9d9d9` | `e` White `#ffffff` |
| `f` Red `#ff0000` | `g` Dark red `#8b0000` | `h` Orange `#ff8000` | `i` Yellow `#ffd800` | `j` Lime `#80ff00` |
| `k` Green `#00a650` | `l` Dark green `#006400` | `m` Teal `#008080` | `n` Cyan `#00ffff` | `o` Sky blue `#87ceeb` |
| `p` Blue `#0000ff` | `q` Navy `#000080` | `r` Violet `#7f00ff` | `s` Purple `#800080` | `t` Magenta `#ff00ff` |
| `u` Pink `#ff8fb8` | `v` Brown `#8b4513` | `w` Tan `#d2b48c` | `x` Peach `#ffcba4` | `y` Salmon `#fa8072` |

In the database, rows are separated by a newline (`CHAR(10)`).

### Table

```sql
CREATE TABLE pixel_art (
    id INT UNSIGNED NOT NULL PRIMARY KEY,      -- random six-digit code, 100000-999999
    width TINYINT UNSIGNED NOT NULL,
    height TINYINT UNSIGNED NOT NULL,
    pixel_code MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## How it works

The whole app is `index.php`: PHP serves the page and a few JSON endpoints, and the JavaScript does all the drawing.

| Endpoint (`POST ?api=…`) | Who | Purpose |
|---|---|---|
| `save` | everyone | Background autosave of the session state (option toggles are only accepted from the boss; `lock` also ends the boss session) |
| `auth` | everyone | Check the boss code and unlock the boss panel |
| `boss-save` | boss | Store the pixel code in MySQL, return the new id |
| `load` | everyone | Look up an id, reset the canvas to a blank one at that resolution, and return the code |

Session contents: resolution, artwork (one character per pixel), selected colour, toggle values, the loaded game code and the boss flag.

## Security notes

- The boss code is checked on the server, never in the browser. Keep `ww_config.php` out of version control (for example add it to `.gitignore` and commit a sample copy instead) and use a strong code.
- Wrong boss codes and wrong game codes are rate-limited (5 and 10 misses in a row, then a one-minute block). The limit is stored in the session, so it slows guessing but a determined attacker who discards cookies can bypass it.
- Six-digit ids are not secrets: anyone who guesses or is given a code can load that picture's code.
- All database access uses prepared statements; the table name from the config is validated before use.
- Serve over HTTPS in production so the boss code and session cookie are not sent in clear text.

## Project layout

```
index.php            the application (PHP + HTML + CSS + JavaScript)
ww_config.php        database and boss-code settings
sample_images.sql    table definition and 20 sample pictures
```

## Roadmap

Ideas not built yet: saving or downloading artwork as an image, more tools beyond the pen, listing and managing stored pictures, and a way to clear a loaded game without using the boss toggle.

## License

No license has been chosen yet. Add a `LICENSE` file before publishing if you want others to reuse the code.
