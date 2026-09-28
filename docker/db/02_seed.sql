-- ─────────────────────────────────────────────────────────────────────────────
-- Grizzly Music Archive — Demo Seed Data
--
-- Safe, publicly known albums used only to demonstrate the application.
-- No personal data, no private notes, no local file paths.
-- To start with a completely empty archive, comment out this file in
-- docker-compose.yml (remove the 02_seed.sql volume line).
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ── Genres ───────────────────────────────────────────────────────────────────
INSERT INTO `genres` (`id`, `name`) VALUES
(1,  'Pop Rock'),
(2,  'Prog Rock'),
(3,  'Art Rock'),
(4,  'Europop'),
(5,  'Alternative Rock'),
(6,  'Blues Rock'),
(7,  'Indie Rock'),
(8,  'Rock'),
(9,  'Soft Rock'),
(10, 'Post-Punk'),
(11, 'Jazz'),
(12, 'Glam'),
(13, 'Folk Rock'),
(14, 'Synth-pop'),
(15, 'Classic Rock'),
(16, 'New Wave'),
(17, 'Grunge'),
(18, 'Post Rock'),
(19, 'Downtempo'),
(20, 'House'),
(21, 'Trip Hop'),
(22, 'Emo'),
(23, 'Alternative Metal'),
(24, 'Punk'),
(25, 'Psychedelic Rock'),
(26, 'Hip Hop'),
(27, 'Noise');

-- ── Labels ───────────────────────────────────────────────────────────────────
INSERT INTO `labels` (`id`, `name`) VALUES
(1, 'Apple Records'),
(2, 'Columbia'),
(3, 'Parlophone'),
(4, 'Capitol Records'),
(5, 'Atlantic'),
(6, 'Geffen Records'),
(7, 'Sub Pop Records'),
(8, 'EMI');

-- ── Artists ──────────────────────────────────────────────────────────────────
INSERT INTO `artists` (`id`, `name`, `slug`, `bio`) VALUES
(1, 'The Beatles',   'the-beatles',   'Legendary British rock band from Liverpool (1960–1970).'),
(2, 'David Bowie',   'david-bowie',   'Iconic British rock musician and actor (1947–2016).'),
(3, 'Pink Floyd',    'pink-floyd',    'British progressive rock band formed in London in 1965.'),
(4, 'Radiohead',     'radiohead',     'British alternative rock band from Abingdon, formed in 1985.'),
(5, 'Nirvana',       'nirvana',       'American grunge band from Aberdeen, Washington (1987–1994).'),
(6, 'Sonic Youth',   'sonic-youth',   'American alternative rock band from New York City (1981–2011).'),
(7, 'Pearl Jam',     'pearl-jam',     'American rock band from Seattle, Washington, formed in 1990.'),
(8, 'Arctic Monkeys','arctic-monkeys','British indie rock band from Sheffield, formed in 2002.');

-- ── Albums ───────────────────────────────────────────────────────────────────
INSERT INTO `albums` (`id`, `artist_id`, `genre_id`, `label_id`, `format_id`, `title`, `slug`, `year`, `condition`, `copies`, `notes`, `cover_url`, `cover_local`, `mbid`) VALUES
(1,  1, 1,  1, 1, 'Abbey Road',                  'abbey-road-1',                  1969, 'Very Good', 1, NULL, 'https://coverartarchive.org/release-group/9162580e-5df4-32de-80cc-f45a8d8a9b1d/front-500', NULL, NULL),
(2,  1, 1,  1, 1, 'Sgt. Pepper''s',              'sgt-peppers-2',                 1967, 'Very Good', 1, NULL, 'https://coverartarchive.org/release-group/9f7a4c28-8fa2-3113-929c-c47a9f7982c3/front-500', NULL, NULL),
(3,  2, 12, 8, 1, 'The Rise and Fall of Ziggy Stardust', 'ziggy-stardust-3',      1972, 'Very Good', 1, NULL, 'https://coverartarchive.org/release-group/6c9ae3dd-32ad-472c-96be-69d0a3536261/front-500', NULL, NULL),
(4,  3, 2,  NULL, 1, 'The Dark Side of the Moon', 'dark-side-of-the-moon-4',      1973, 'Near Mint', 1, NULL, 'https://coverartarchive.org/release-group/f5093c06-23e3-404f-aeaa-40f72885ee3a/front-500', NULL, NULL),
(5,  3, 2,  NULL, 1, 'Wish You Were Here',        'wish-you-were-here-5',         1975, 'Very Good', 1, NULL, 'https://coverartarchive.org/release-group/1a272023-10d3-38ee-bab3-317b55fcc21d/front-500', NULL, NULL),
(6,  4, 5,  3,  2, 'OK Computer',                 'ok-computer-6',                1997, 'Very Good', 1, NULL, 'https://coverartarchive.org/release-group/b1392450-e666-3926-a536-22c65f834433/front-500', NULL, NULL),
(7,  4, 5,  3,  4, 'Kid A',                       'kid-a-7',                      2000, 'Very Good', 1, NULL, 'https://coverartarchive.org/release-group/e75c0549-ad55-39e3-8025-c72c5d4a3c5d/front-500', NULL, NULL),
(8,  5, 17, 7,  1, 'Nevermind',                   'nevermind-8',                  1991, 'Very Good', 1, NULL, 'https://coverartarchive.org/release-group/1b022e01-4da6-387b-8658-8678046e4cef/front-500', NULL, NULL),
(9,  5, 17, 7,  2, 'In Utero',                    'in-utero-9',                   1993, 'Very Good', 1, NULL, 'https://coverartarchive.org/release-group/2a0981fb-9593-3019-864b-ce934d97a16e/front-500', NULL, NULL),
(10, 6, 5,  6,  2, 'Daydream Nation',             'daydream-nation-10',           1988, 'Very Good', 1, NULL, 'https://coverartarchive.org/release-group/24769a99-8189-3d8c-947e-dbc8574dad5c/front-500', NULL, NULL),
(11, 7, 5,  5,  2, 'Ten',                          'ten-11',                       1991, 'Very Good', 1, NULL, 'https://coverartarchive.org/release-group/cea5d18a-1924-3cda-bebc-38933834b25d/front-500', NULL, NULL),
(12, 8, 7,  2,  1, 'AM',                           'am-12',                        2013, 'Mint',      1, NULL, 'https://coverartarchive.org/release-group/a348ba2f-f8b3-4686-b928-e63d8d94d543/front-500', NULL, NULL);

-- ── Album formats (bridge) ──────────────────────────────────────────────────
-- One row per owned format; derived from the demo albums' primary format.
INSERT INTO `album_formats` (`album_id`, `format_id`, `is_manual`, `is_scanner`)
SELECT `id`, `format_id`, 1, 0 FROM `albums`;

-- ── Tracks ────────────────────────────────────────────────────────────────────
-- Abbey Road (album 1)
INSERT INTO `tracks` (`album_id`, `position`, `title`, `duration_sec`) VALUES
(1, 1,  'Come Together',              259),
(1, 2,  'Something',                  182),
(1, 3,  'Maxwell''s Silver Hammer',   207),
(1, 4,  'Oh! Darling',                207),
(1, 5,  'Octopus''s Garden',          170),
(1, 6,  'I Want You (She''s So Heavy)', 468),
(1, 7,  'Here Comes the Sun',         185),
(1, 8,  'Because',                    165),
(1, 9,  'You Never Give Me Your Money', 242),
(1, 10, 'Sun King',                   146),
(1, 11, 'Mean Mr. Mustard',           66),
(1, 12, 'Polythene Pam',              72),
(1, 13, 'She Came In Through the Bathroom Window', 117),
(1, 14, 'Golden Slumbers',            91),
(1, 15, 'Carry That Weight',          96),
(1, 16, 'The End',                    141),
(1, 17, 'Her Majesty',                23);

-- The Dark Side of the Moon (album 4)
INSERT INTO `tracks` (`album_id`, `position`, `title`, `duration_sec`) VALUES
(4, 1,  'Speak to Me',                68),
(4, 2,  'Breathe',                   169),
(4, 3,  'On the Run',                216),
(4, 4,  'Time',                      421),
(4, 5,  'The Great Gig in the Sky',  284),
(4, 6,  'Money',                     382),
(4, 7,  'Us and Them',               462),
(4, 8,  'Any Colour You Like',       205),
(4, 9,  'Brain Damage',              228),
(4, 10, 'Eclipse',                   123);

-- OK Computer (album 6)
INSERT INTO `tracks` (`album_id`, `position`, `title`, `duration_sec`) VALUES
(6, 1,  'Airbag',                    309),
(6, 2,  'Paranoid Android',          383),
(6, 3,  'Subterranean Homesick Alien', 272),
(6, 4,  'Exit Music (For a Film)',   244),
(6, 5,  'Let Down',                  299),
(6, 6,  'Karma Police',              264),
(6, 7,  'Fitter Happier',            116),
(6, 8,  'Electioneering',            230),
(6, 9,  'Climbing Up the Walls',     245),
(6, 10, 'No Surprises',              228),
(6, 11, 'Lucky',                     258),
(6, 12, 'The Tourist',               324);

-- Nevermind (album 8)
INSERT INTO `tracks` (`album_id`, `position`, `title`, `duration_sec`) VALUES
(8, 1,  'Smells Like Teen Spirit',   301),
(8, 2,  'In Bloom',                  255),
(8, 3,  'Come as You Are',           219),
(8, 4,  'Breed',                     183),
(8, 5,  'Lithium',                   257),
(8, 6,  'Polly',                     177),
(8, 7,  'Territorial Pissings',      143),
(8, 8,  'Drain You',                 223),
(8, 9,  'Lounge Act',                156),
(8, 10, 'Stay Away',                 212),
(8, 11, 'On a Plain',                196),
(8, 12, 'Something in the Way',      231);

SET FOREIGN_KEY_CHECKS = 1;
