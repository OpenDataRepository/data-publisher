-- MySQL dump 10.13  Distrib 5.5.44, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: odr_alpha
-- ------------------------------------------------------
-- Server version	5.5.44-0ubuntu0.12.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `odr_field_type`
--

DROP TABLE IF EXISTS `odr_field_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `odr_field_type` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(45) NOT NULL,
  `description` text,
  `created` datetime NOT NULL,
  `createdBy` int(11) DEFAULT NULL,
  `insert_on_create` tinyint(1) NOT NULL,
  `type_class` varchar(45) NOT NULL,
  `allow_multiple` tinyint(1) NOT NULL,
  `can_be_unique` tinyint(1) NOT NULL,
  `can_be_sort_field` tinyint(1) NOT NULL,
  `deletedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_9A1792BCD3564642` (`createdBy`),
  CONSTRAINT `FK_9A1792BCD3564642` FOREIGN KEY (`createdBy`) REFERENCES `fos_user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `odr_field_type`
--

LOCK TABLES `odr_field_type` WRITE;
/*!40000 ALTER TABLE `odr_field_type` DISABLE KEYS */;
INSERT INTO `odr_field_type` VALUES (1,'Boolean','Boolean values have states of true/false or 1/0.','0000-00-00 00:00:00',1,1,'Boolean',0,0,0,NULL),(2,'File','Files can be uploaded for storage or parsing into field values.','0000-00-00 00:00:00',1,0,'File',0,0,0,NULL),(3,'Image','Images for storage or display.','0000-00-00 00:00:00',1,0,'Image',0,0,0,NULL),(4,'Integer','Stores integer values.','0000-00-00 00:00:00',1,1,'IntegerValue',0,1,1,NULL),(5,'Paragraph Text','A long text field for storage of very large character strings.','0000-00-00 00:00:00',1,1,'LongText',0,0,0,NULL),(6,'Long Text','Character strings up to 255 characters in length.','0000-00-00 00:00:00',1,1,'LongVarchar',0,1,1,NULL),(7,'Medium Text','Medium length character strings up to 64 characters in length.','0000-00-00 00:00:00',1,1,'MediumVarchar',0,1,1,NULL),(8,'Single Radio','A set of radio buttons that allow a single selection.','0000-00-00 00:00:00',1,0,'Radio',0,0,0,NULL),(9,'Short Text','A short text field up to 32 characters in length.','0000-00-00 00:00:00',1,1,'ShortVarchar',0,1,1,NULL),(10,'XYZ Data','X, Y, Z data for plotting and download.  Usually, this data is parsed from an input file.','0000-00-00 00:00:00',1,1,'XyzValue',0,0,0,'2014-04-07 00:00:00'),(11,'DateTime','A date value with time.','0000-00-00 00:00:00',1,1,'DatetimeValue',0,0,1,NULL),(12,'Checkbox','A dual or three state checkbox.','2013-02-01 00:00:00',1,0,'Checkbox',0,0,0,'2014-04-07 00:00:00'),(13,'Multiple Radio','A set of radio buttons that allow multiple selections.','2013-07-24 00:00:00',2,0,'Radio',0,0,0,NULL),(14,'Single Select','A dropdown menu that allows a single selection.','2013-07-24 00:00:00',2,0,'Radio',0,0,0,NULL),(15,'Multiple Select','A dropdown menu that allows multiple selections.','2013-07-24 00:00:00',2,0,'Radio',0,0,0,NULL),(16,'Decimal','Stores decimal values.','2014-04-07 00:00:00',2,1,'DecimalValue',0,1,1,NULL),(17,'Markdown','Datafield that displays the same Markdown-compliant text across all DataRecords.','2014-12-15 00:00:00',2,0,'Markdown',0,0,0,NULL)
/*!40000 ALTER TABLE `odr_field_type` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `odr_render_plugin`
--

DROP TABLE IF EXISTS `odr_render_plugin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `odr_render_plugin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plugin_name` varchar(32) COLLATE utf8_unicode_ci NOT NULL,
  `description` longtext COLLATE utf8_unicode_ci,
  `plugin_class_name` varchar(128) COLLATE utf8_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL,
  `deletedAt` date DEFAULT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `createdBy` int(11) DEFAULT NULL,
  `updatedBy` int(11) DEFAULT NULL,
  `plugin_type` smallint(6) NOT NULL,
  `override_child` tinyint(1) NOT NULL,
  `override_fields` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_8BFAC6BCD3564642` (`createdBy`),
  KEY `IDX_8BFAC6BCE8DE7170` (`updatedBy`),
  CONSTRAINT `FK_8BFAC6BCD3564642` FOREIGN KEY (`createdBy`) REFERENCES `fos_user` (`id`),
  CONSTRAINT `FK_8BFAC6BCE8DE7170` FOREIGN KEY (`updatedBy`) REFERENCES `fos_user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `odr_render_plugin`
--

LOCK TABLES `odr_render_plugin` WRITE;
/*!40000 ALTER TABLE `odr_render_plugin` DISABLE KEYS */;
INSERT INTO `odr_render_plugin` VALUES (1,'Default Render','Render the fields using the default renderer for the field type.','graph.defaultplugin',1,NULL,'2013-03-02 00:00:00','2013-03-02 00:00:00',1,1,2,0,0),(2,'Graph Plugin','The Graph Plugin plots XY data in a line plot on a linear or log chart.  A data type with the required fields is required and multiple instances of the XY data files are allowed.  If more than one XY file is found, it will be labeled and displayed on a roll-up graph initially.  The plugin then allows filtering to a specific file using the meta data provided.','graph.graphplugin',1,NULL,'2013-10-13 00:00:00','2013-10-13 00:00:00',1,1,1,1,0),(3,'Diffraction Graph','Copy/Paste of Raman graph, for testing purposes','graph.diffractionplugin',0,NULL,'2013-11-01 00:00:00','2013-11-01 00:00:00',2,2,1,0,0),(5,'Chemistry Field','A datafield containing a chemical formula.','graph.chemistryplugin',1,NULL,'2013-11-04 00:00:00','2014-07-25 14:14:21',2,2,3,0,0),(6,'References Plugin','The References Plugin parses multiple linked DataRecords and returns HTML that looks like something you would expect to see for multiple references...','graph.referencesplugin',1,NULL,'2014-03-18 00:00:00','2014-03-18 00:00:00',2,2,1,0,1),(8,'Comment Plugin','Allows users to enter comments and displays the comment history.','graph.commentplugin',1,NULL,'2014-03-20 12:00:00','2014-03-20 12:00:00',1,1,1,1,1),(9,'URL Field','A datafield containing a HTML link.','graph.urlplugin',1,NULL,'2015-05-20 00:00:00','2015-05-20 00:00:00',2,2,3,0,0);
/*!40000 ALTER TABLE `odr_render_plugin` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `odr_theme`
--

DROP TABLE IF EXISTS `odr_theme`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `odr_theme` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_name` varchar(64) NOT NULL,
  `template_description` text,
  `template_preview` longblob,
  `is_default` tinyint(1) NOT NULL,
  `deletedAt` date DEFAULT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `createdBy` int(11) DEFAULT NULL,
  `updatedBy` int(11) DEFAULT NULL,
  `template_type` varchar(32) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_E60A9FAFD3564642` (`createdBy`),
  KEY `IDX_E60A9FAFE8DE7170` (`updatedBy`),
  CONSTRAINT `FK_E60A9FAFD3564642` FOREIGN KEY (`createdBy`) REFERENCES `fos_user` (`id`),
  CONSTRAINT `FK_E60A9FAFE8DE7170` FOREIGN KEY (`updatedBy`) REFERENCES `fos_user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `odr_theme`
--

LOCK TABLES `odr_theme` WRITE;
/*!40000 ALTER TABLE `odr_theme` DISABLE KEYS */;
INSERT INTO `odr_theme` VALUES (1,'Default','Default theme.',NULL,1,NULL,'0000-00-00 00:00:00','0000-00-00 00:00:00',1,1,'form'),(2,'Search Short Result','Search result summary view.',NULL,1,NULL,'2013-06-19 00:00:00','2013-06-19 00:00:00',1,1,'search_short'),(3,'Search Long Display','Long version search summary result.',NULL,1,NULL,'2013-06-19 00:00:00','2013-06-19 00:00:00',1,1,'search_long'),(4,'Linked Data Type Display','Display format for linked data type.  This display shows in the form view of a parent data type that the linked data type is linked to. \r\n\r\nUsers who can edit a parent type, but not the child type will see this template and be able to select a new instance of the child type but not edit the child type itself.',NULL,1,NULL,'2013-06-19 00:00:00','2013-06-19 00:00:00',1,1,'linked_data_display');
/*!40000 ALTER TABLE `odr_theme` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2015-08-15 16:25:23
