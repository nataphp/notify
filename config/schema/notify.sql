/*
 Navicat Premium Dump SQL

 Source Server         : localhost
 Source Server Type    : MySQL
 Source Server Version : 80200 (8.2.0)
 Source Host           : localhost:3306
 Source Schema         : proescala

 Target Server Type    : MySQL
 Target Server Version : 80200 (8.2.0)
 File Encoding         : 65001

 Date: 07/05/2026 11:33:42
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for notify_notification_deliveries
-- ----------------------------
CREATE TABLE IF NOT EXISTS `notify_notification_deliveries`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` char(36) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `recipient_model` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_id` int UNSIGNED NOT NULL,
  `definition_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `dedupe_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `attempts` smallint UNSIGNED NOT NULL DEFAULT 0,
  `vars` json NULL,
  `delivery_opts` json NULL,
  `rendered` json NULL,
  `transport_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `claimed_at` datetime NULL DEFAULT NULL,
  `available_at` datetime NOT NULL COMMENT 'When is the row available for processing/dispatch',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` datetime NULL DEFAULT NULL,
  `failed_at` datetime NULL DEFAULT NULL,
  `processed_at` datetime NULL DEFAULT NULL COMMENT 'Legacy/back-compat only: mirrors COALESCE(sent_at, failed_at)',
  `read_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_status_available`(`status` ASC, `available_at` ASC, `id` ASC) USING BTREE,
  INDEX `idx_recipient_dedupe`(`recipient_model` ASC, `recipient_id` ASC, `dedupe_key` ASC) USING BTREE,
  INDEX `idx_request`(`request_id` ASC) USING BTREE,
  INDEX `idx_inapp_list`(`recipient_model` ASC, `recipient_id` ASC, `channel` ASC, `created` ASC) USING BTREE,
  INDEX `idx_inapp_unread`(`recipient_model` ASC, `recipient_id` ASC, `channel` ASC, `read_at` ASC, `created` ASC) USING BTREE,
  CONSTRAINT `notify_notification_deliveries_chk_1` CHECK (json_valid(`vars`)),
  CONSTRAINT `notify_notification_deliveries_chk_2` CHECK (json_valid(`delivery_opts`)),
  CONSTRAINT `notify_notification_deliveries_chk_3` CHECK (json_valid(`rendered`))
) ENGINE = InnoDB AUTO_INCREMENT = 7 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for notify_notification_preferences
-- ----------------------------
CREATE TABLE IF NOT EXISTS `notify_notification_preferences`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient_model` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_id` int UNSIGNED NOT NULL,
  `definition_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_recipient_key`(`recipient_model` ASC, `recipient_id` ASC, `definition_key` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for notify_push_subscriptions
-- ----------------------------
CREATE TABLE IF NOT EXISTS `notify_push_subscriptions`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient_model` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_id` int UNSIGNED NOT NULL,
  `endpoint` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `endpoint_hash` char(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `p256dh` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `auth` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uniq_endpoint_hash`(`endpoint_hash` ASC) USING BTREE,
  INDEX `idx_recipient_created`(`recipient_model` ASC, `recipient_id` ASC, `created` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 14 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

SET FOREIGN_KEY_CHECKS = 1;
