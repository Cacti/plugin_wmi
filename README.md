# Cacti WMI Plugin for Windows Devices

The WMI Plugin provides data collection services for devices that support the
WMI protocol.  It operates asyncrhonously to the Cacti data collection and
stores the resulting information into cache tables.  These cache tables can then
be used to generate graphs, thresholds and alerts.  It relies on the 'wmic'
command in Linux, and WMI services via Microsofts COM protocol from Windows
Cacti servers.

### WARNING: Development in progress

This plugin is NOT fully functional.  It should not be used on production
systems.  Use at your own risk!

## Installation

To install the plugin, simply copy the plugin_wmi directory to Cacti's plugins
directory and rename it to simply 'wmi'.  Once this is complete, goto Cacti's
Plugin Management section, and Install and Enable the plugin.  Once this is
complete, you can start to define WMI queries for your Windows and other Device
Templates that support WMI.

Make certain that you install the wmic binary if you are planning on running on
Linux.

## Usage

The WMI plugin allows you to define multiple Queries, and then associate those
queries with Cacti Device Templates.  Therefore, if you have a generic Windows
Device Template, you can add those queries that are significant for a general
Windows device.  If you have a Microsoft SQL Server Device Template, you can add
WMI Queries that are specific to Windows SQL Server there.

There is presently only one setting support in the WMI plugin, and that is to
Autocreate the actual WMI polling at device creation or update based upon the
WMI Queries defined as a part of the Device Template.  This setting can be found
unser Settings -> Device Defaults.

## Authors

The wmi plugin has been was developed several years ago but never actually
finished.  This release formalizes it's behavior and provides a new level of
scalability to Cacti when gathering data from WMI compatible device's.

## ChangeLog

--- 1.1 ---

* issue#1: PHP and SQL backtrace when deleting stored WMI queries or credentials
* issue#2: Issues with passwords containing unescaped special chars

--- 1.0 ---

* feature: Initial Support for Cacti 1.0
