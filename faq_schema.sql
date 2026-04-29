-- ─────────────────────────────────────────────────────────────────
--  DA360 — FAQ Table Schema
--  Run this once in your MySQL database
-- ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS course_faqs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id   INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NOT NULL,
    category    ENUM('Program','Delivery','Placement','Certification','Fee') NOT NULL,
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- 1 to 10
    question    TEXT NOT NULL,
    answer      TEXT NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- prevent duplicate positions per course+location+category
    UNIQUE KEY uq_faq_position (course_id, location_id, category, sort_order),

    KEY idx_course_location (course_id, location_id),
    KEY idx_category        (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
