-- ═══════════════════════════════════════
-- DA360 CMS – Database Schema
-- Run this once to set up all tables
-- ═══════════════════════════════════════

CREATE DATABASE IF NOT EXISTS `da360_cms`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `da360_cms`;

-- ── Courses Table ──────────────────────
CREATE TABLE IF NOT EXISTS `courses` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug`       VARCHAR(100) NOT NULL UNIQUE,
  `label`      VARCHAR(255) NOT NULL,
  `is_active`  TINYINT(1)  NOT NULL DEFAULT 1,
  `sort_order` INT          NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Locations Table ────────────────────
CREATE TABLE IF NOT EXISTS `locations` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug`       VARCHAR(100) NOT NULL UNIQUE,
  `label`      VARCHAR(255) NOT NULL,
  `is_active`  TINYINT(1)  NOT NULL DEFAULT 1,
  `sort_order` INT          NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Course Content Table ───────────────
CREATE TABLE IF NOT EXISTS `course_content` (
  `id`                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `course_id`                INT UNSIGNED NOT NULL,
  `location_id`              INT UNSIGNED NOT NULL,
  `lifeatda360`              TEXT,
  `trustedbylearners`        TEXT,
  `coursehilightcatergory`   TEXT,
  `toolsheading`             VARCHAR(500),
  `toolsdescription`         TEXT,
  `aitoolsheading`           VARCHAR(500),
  `aitoolsdescription`       TEXT,
  `casestudiesheading`       TEXT,
  `casestudeiessubheading`   TEXT,
  `peoplesliderdesc`         TEXT,
  `latestblogheading`        VARCHAR(500),
  `leadcapturetwotitle`      VARCHAR(255),
  `leadcapturethirdtitle`    VARCHAR(255),
  `leadcapturesubtitle`      VARCHAR(255),
  `cohortheading`            TEXT,
  `storyheading`             VARCHAR(500),
  `storydesc`                TEXT,
  `roadmapheader`            VARCHAR(500),
  `roadmapdesc`              TEXT,
  `programskillheading`      TEXT,
  `programskillsubheading`   TEXT,
  `created_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_course_location` (`course_id`, `location_id`),
  FOREIGN KEY (`course_id`)   REFERENCES `courses`(`id`)   ON DELETE CASCADE,
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═══════════════════════════════════════
-- Seed Data
-- ═══════════════════════════════════════

-- Courses
INSERT IGNORE INTO `courses` (`slug`, `label`, `sort_order`) VALUES
('digital-marketing', 'Digital Marketing & E-Commerce', 1),
('seo',               'SEO & Content Marketing',         2);

-- Locations
INSERT IGNORE INTO `locations` (`slug`, `label`, `sort_order`) VALUES
('global',       'Global',       1),
('bangalore',    'Bangalore',    2),
('jayanagar',    'Jayanagar',    3),
('jpnagar',      'JP Nagar',     4),
('malleshwaram', 'Malleshwaram', 5);

-- ── Seed: Digital Marketing × Global ──
INSERT IGNORE INTO `course_content`
  (`course_id`, `location_id`,
   `lifeatda360`, `trustedbylearners`, `coursehilightcatergory`,
   `toolsheading`, `toolsdescription`,
   `aitoolsheading`, `aitoolsdescription`,
   `casestudiesheading`, `casestudeiessubheading`,
   `peoplesliderdesc`, `latestblogheading`,
   `leadcapturetwotitle`, `leadcapturethirdtitle`, `leadcapturesubtitle`,
   `cohortheading`, `storyheading`, `storydesc`,
   `roadmapheader`, `roadmapdesc`,
   `programskillheading`, `programskillsubheading`)
SELECT
  c.id, l.id,
  'Experience Skill Driven Battles & Vibrant Campus Life at the Leading Digital Marketing Classes',
  'With a thriving community of 50,000+ alumni, Digital Academy 360 has empowered learners worldwide through its Digital Marketing E-Commerce Course, helping them build successful careers in the digital world',
  'A Snapshot of What Makes Our E-Commerce Marketing Course a Game-Changer',
  'Digital Marketing & E-Commerce Course', '',
  'Next-Gen AI-Powered Tools',
  'AI empowers modern marketers to blend creativity with performance — and our PG in Digital Marketing equips you with the advanced skills to master both',
  'Brand Case Studies from Our Digital Marketing & E-Commerce Course',
  'Learn from Real Business Challenges with Leading Industry Brands',
  'The One Who Experienced Post Graduate Certification in AI-Powered Digital Marketing & E-Commerce Course',
  'Latest Blogs on Digital Marketing & E-Commerce',
  '', '', '',
  'Post Graduate Certification in AI-Powered Digital Marketing & E-Commerce Course Cohort Details & Upcoming Batches Details',
  'Real Stories. Real Impact. Real Careers.',
  'Meet the Learners Who Transformed Their Futures with Our Digital Marketing AI Course',
  'Your 6-Month Learning',
  'Our Digital Marketing Classes are strategically designed for maximum growth at every stage',
  'Key Highlights of Our AI-Driven Digital Marketing & E-Commerce Course',
  'AI Digital Marketing & E-Commerce Skills You''ll Master to Lead the Future'
FROM courses c, locations l
WHERE c.slug = 'digital-marketing' AND l.slug = 'global';

-- ── Seed: Digital Marketing × Bangalore ──
INSERT IGNORE INTO `course_content`
  (`course_id`, `location_id`,
   `lifeatda360`, `trustedbylearners`, `coursehilightcatergory`,
   `toolsheading`, `toolsdescription`,
   `aitoolsheading`, `aitoolsdescription`,
   `casestudiesheading`, `casestudeiessubheading`,
   `peoplesliderdesc`, `latestblogheading`,
   `leadcapturetwotitle`, `leadcapturethirdtitle`, `leadcapturesubtitle`,
   `cohortheading`, `storyheading`, `storydesc`,
   `roadmapheader`, `roadmapdesc`,
   `programskillheading`, `programskillsubheading`)
SELECT
  c.id, l.id,
  'Experience Skill Driven Battles & Vibrant Campus Life at the Leading Digital Marketing Classes in Bangalore',
  'Not only learn but also specialize in digital marketing & e-commerce courses. Join the 50,000+ community of digital marketers who trusted us for PG certification in digital marketing and ecommerce courses in Bangalore',
  'Course Highlights Of New Age Agentic E-Commerce Marketing Courses in Bangalore',
  'Digital Marketing & E-Commerce Course in Bangalore', '',
  'Next-Gen AI-Powered Tools',
  'PG Digital Marketing in Bangalore by Digital Academy 360 empowers modern marketers to blend creativity with performance.',
  'Brand Case Studies from Our Digital Marketing & E-Commerce Course in Bangalore',
  'Gain Hands-On Experience Solving Challenges from Top Brands',
  'The One Who Experienced Post Graduate Certification in AI-Powered Digital Marketing & E-Commerce Course in Bangalore',
  'Latest Blogs on Digital Marketing & E-Commerce in Bangalore',
  'In Bangalore', '', '',
  'Post Graduate Certification Course in AI-Powered Digital Marketing & E-Commerce in Bangalore Cohort & Upcoming Batches Details',
  'Real Stories. Real Impact. Real Careers.',
  'Meet the Learners Who Transformed Their Careers with Our Digital Marketing AI Courses in Bangalore',
  'Your 6-Month Learning',
  'Our Digital Marketing Classes in Bangalore are designed to help you grow and succeed at every stage',
  'Key Highlights of Our AI-Driven Digital Marketing & E-Commerce Course in Bangalore',
  'AI Digital Marketing & E-Commerce Skills in Bangalore That Empower You to Lead'
FROM courses c, locations l
WHERE c.slug = 'digital-marketing' AND l.slug = 'bangalore';

-- ── Seed: Digital Marketing × Jayanagar ──
INSERT IGNORE INTO `course_content`
  (`course_id`, `location_id`,
   `lifeatda360`, `trustedbylearners`, `coursehilightcatergory`,
   `toolsheading`, `toolsdescription`,
   `aitoolsheading`, `aitoolsdescription`,
   `casestudiesheading`, `casestudeiessubheading`,
   `peoplesliderdesc`, `latestblogheading`,
   `leadcapturetwotitle`, `leadcapturethirdtitle`, `leadcapturesubtitle`,
   `cohortheading`, `storyheading`, `storydesc`,
   `roadmapheader`, `roadmapdesc`,
   `programskillheading`, `programskillsubheading`)
SELECT
  c.id, l.id,
  'Experience Skill Driven Battles & Vibrant Campus Life at the Leading Digital Marketing Classes in Jayanagar',
  'Not only learn but also specialize in digital marketing & e-commerce courses. Join the 50,000+ community of digital marketers who trusted us for PG certification in digital marketing and ecommerce courses in Jayanagar',
  'Discover Why Our E-Commerce Marketing Courses in Jayanagar Is a Game-Changer',
  'Digital Marketing & E-Commerce Course in Jayanagar', '',
  'Next-Gen AI-Powered Tools',
  'Digital Academy 360''s PG Digital Marketing in Jayanagar Enables Modern Marketers to Fuse Creativity with Performance.',
  'Brand Case Studies from Our Digital Marketing & E-Commerce Course in Jayanagar',
  'Learn by Solving Challenges from the World''s Leading Brands',
  'The One Who Experienced Post Graduate Certification in AI-Powered Digital Marketing & E-Commerce Course in Jayanagar',
  'Latest Blogs on Digital Marketing & E-Commerce in Jayanagar',
  'In Jayanagar', '', '',
  'Post Graduate Certification Course in AI-Powered Digital Marketing & E-Commerce in Jayanagar Cohort & Upcoming Batches Details',
  'Real Stories. Real Impact. Real Careers.',
  'Discover How Learners Shaped Their Futures with Our Digital Marketing AI Courses in Jayanagar',
  'Your 6-Month Learning',
  'Experience our Digital Marketing Classes in Jayanagar, designed to help you grow at every stage.',
  'Key Highlights of Our AI-Driven Digital Marketing & E-Commerce Course in Jayanagar',
  'Master AI Digital Marketing & E-Commerce Skills in Jayanagar to Shape the Future'
FROM courses c, locations l
WHERE c.slug = 'digital-marketing' AND l.slug = 'jayanagar';

-- ── Seed: Digital Marketing × JP Nagar ──
INSERT IGNORE INTO `course_content`
  (`course_id`, `location_id`,
   `lifeatda360`, `trustedbylearners`, `coursehilightcatergory`,
   `toolsheading`, `toolsdescription`,
   `aitoolsheading`, `aitoolsdescription`,
   `casestudiesheading`, `casestudeiessubheading`,
   `peoplesliderdesc`, `latestblogheading`,
   `leadcapturetwotitle`, `leadcapturethirdtitle`, `leadcapturesubtitle`,
   `cohortheading`, `storyheading`, `storydesc`,
   `roadmapheader`, `roadmapdesc`,
   `programskillheading`, `programskillsubheading`)
SELECT
  c.id, l.id,
  'Step into a dynamic learning environment where skill-driven challenges meet an energetic campus culture at the top-rated Digital Marketing Classes in JP Nagar.',
  'Go beyond learning and specialize in digital marketing & e-commerce courses. Join the 50,000+ community of digital marketers who trusted us for PG certification in digital marketing and ecommerce courses in JP Nagar',
  'Transform your career with the New Age Agentic E-Commerce Marketing Courses in JP Nagar',
  'Digital Marketing & E-Commerce Course in JP Nagar', '',
  'Next-Gen AI-Powered Tools',
  'Empower Your Marketing Career with Digital Academy 360''s PG Digital Marketing in JP Nagar—Where Creativity Meets Results',
  'Brand Case Studies from Our Digital Marketing & E-Commerce Course in JP Nagar',
  'Hands-On Learning Through Real Business Scenarios from Top Industry Brands',
  'The One Who Experienced Post Graduate Certification in AI-Powered Digital Marketing & E-Commerce Course in JP Nagar',
  'Latest Blogs on Digital Marketing & E-Commerce in JP Nagar',
  'In JP Nagar', '', '',
  'Post Graduate Certification Course in AI-Powered Digital Marketing & E-Commerce in JP Nagar Cohort & Upcoming Batches Details',
  'Real Stories. Real Impact. Real Careers.',
  'Meet the Learners Who Elevated Their Careers with Our Digital Marketing AI Courses in JP Nagar',
  'Your 6-Month Learning',
  'Experience our Digital Marketing Classes in JP Nagar, designed to help you grow at every stage.',
  'Key Highlights of Our AI-Driven Digital Marketing & E-Commerce Course in JP Nagar',
  'Master AI Digital Marketing & E-Commerce Skills in JP Nagar to Shape the Future'
FROM courses c, locations l
WHERE c.slug = 'digital-marketing' AND l.slug = 'jpnagar';

-- ── Seed: Digital Marketing × Malleshwaram ──
INSERT IGNORE INTO `course_content`
  (`course_id`, `location_id`,
   `lifeatda360`, `trustedbylearners`, `coursehilightcatergory`,
   `toolsheading`, `toolsdescription`,
   `aitoolsheading`, `aitoolsdescription`,
   `casestudiesheading`, `casestudeiessubheading`,
   `peoplesliderdesc`, `latestblogheading`,
   `leadcapturetwotitle`, `leadcapturethirdtitle`, `leadcapturesubtitle`,
   `cohortheading`, `storyheading`, `storydesc`,
   `roadmapheader`, `roadmapdesc`,
   `programskillheading`, `programskillsubheading`)
SELECT
  c.id, l.id,
  'Discover Skill-Focused Training and a Dynamic Campus Life at Leading Digital Marketing Classes in Malleshwaram',
  'Move beyond generic digital marketing and e-commerce programs. Join 50,000+ learners who trusted us for PG certification in digital marketing and e-commerce courses in Malleshwaram.',
  'AI-Led E-Commerce Marketing Course Highlights in Malleshwaram',
  'Digital Marketing & E-Commerce Course in Malleshwaram', '',
  'Next-Gen AI-Powered Tools',
  'Empower Your Marketing Career with Digital Academy 360''s PG Digital Marketing in Malleshwaram—Where Creativity Meets Results',
  'Brand Case Studies from Our Digital Marketing & E-Commerce Course in Malleshwaram',
  'Hands-On Learning Through Real Business Scenarios from Top Industry Brands',
  'The One Who Experienced Post Graduate Certification in AI-Powered Digital Marketing & E-Commerce Course in Malleshwaram',
  'Latest Blogs on Digital Marketing & E-Commerce in Malleshwaram',
  'In Malleshwaram', '', '',
  'Post Graduate Certification Course in AI-Powered Digital Marketing & E-Commerce in Malleshwaram Cohort Details & Upcoming Batches Details',
  'Real Stories. Real Impact. Real Careers.',
  'Meet the Learners Who Elevated Their Careers with Our Digital Marketing AI Courses in Malleshwaram',
  'Your 6-Month Learning',
  'Experience our Digital Marketing Classes in Malleshwaram, designed to help you grow at every stage.',
  'Key Highlights of Our AI-Driven Digital Marketing & E-Commerce Course in Malleshwaram',
  'Master AI Digital Marketing & E-Commerce Skills in Malleshwaram to Shape the Future'
FROM courses c, locations l
WHERE c.slug = 'digital-marketing' AND l.slug = 'malleshwaram';
