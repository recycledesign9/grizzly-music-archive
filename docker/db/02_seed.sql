-- ─────────────────────────────────────────────────────────────────────────────
-- Grizzly Music Archive — Demo Seed Data
--
-- Safe, publicly known albums used only to demonstrate the application.
-- No personal data, no private notes, no local file paths.
-- To start with a completely empty archive, comment out this file in
-- docker-compose.yml (remove the 02_seed.sql volume line).
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ── Formats ──────────────────────────────────────────────────────────────────
INSERT INTO `formats` (`id`, `name`) VALUES
(1, 'Vinile'),
(2, 'CD'),
(3, 'Musicassetta'),
(4, 'Digital');

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
(1,  1, 1,  1, 1, 'Abbey Road',                  'abbey-road-1',                  1969, 'Very Good', 1, NULL, NULL, NULL, '1b022e01-4da6-387b-8658-8678046e4cef'),
(2,  1, 1,  1, 1, 'Sgt. Pepper''s',              'sgt-peppers-2',                 1967, 'Very Good', 1, NULL, NULL, NULL, '3a6f54c4-2d3b-473a-85b4-f8d0b77f8a4e'),
(3,  2, 12, 8, 1, 'The Rise and Fall of Ziggy Stardust', 'ziggy-stardust-3',      1972, 'Very Good', 1, NULL, NULL, NULL, 'b1572da5-e2c0-4e3a-85b4-e1d8b16e7f2a'),
(4,  3, 2,  NULL, 1, 'The Dark Side of the Moon', 'dark-side-of-the-moon-4',      1973, 'Near Mint', 1, NULL, NULL, NULL, 'b4e8a4f2-1578-4ab6-b4c3-7f4b3d9b8c5a'),
(5,  3, 2,  NULL, 1, 'Wish You Were Here',        'wish-you-were-here-5',         1975, 'Very Good', 1, NULL, NULL, NULL, 'a1c9a681-d2ac-4f46-8df0-4e8d9b1e5c3a'),
(6,  4, 5,  3,  2, 'OK Computer',                 'ok-computer-6',                1997, 'Very Good', 1, NULL, NULL, NULL, 'b3b7a6a4-1e3b-4b5c-8d9e-2f1a6b4c8d7e'),
(7,  4, 5,  3,  4, 'Kid A',                       'kid-a-7',                      2000, 'Very Good', 1, NULL, NULL, NULL, 'b2975dc4-db07-4b18-b928-1e34d5b9f3c2'),
(8,  5, 17, 7,  1, 'Nevermind',                   'nevermind-8',                  1991, 'Very Good', 1, NULL, NULL, NULL, 'b52a8d00-f8d2-3e3e-9e3b-4c2d5b8a7f1c'),
(9,  5, 17, 7,  2, 'In Utero',                    'in-utero-9',                   1993, 'Very Good', 1, NULL, NULL, NULL, 'a3b4c5d6-e7f8-9a0b-c1d2-e3f4a5b6c7d8'),
(10, 6, 5,  6,  2, 'Daydream Nation',             'daydream-nation-10',           1988, 'Very Good', 1, NULL, NULL, NULL, '7c1e1ac4-3239-39d6-9986-55ca1bdeb1bf'),
(11, 7, 5,  5,  2, 'Ten',                          'ten-11',                       1991, 'Very Good', 1, NULL, NULL, NULL, '9154cfe5-ef58-458f-b5d4-eb6ca3e404c4'),
(12, 8, 7,  2,  1, 'AM',                           'am-12',                        2013, 'Mint',      1, NULL, NULL, NULL, '700950cf-5491-4349-87f8-5b85b40f0340');

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
