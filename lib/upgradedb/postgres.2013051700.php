<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2013 LMS Developers
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307,
 *  USA.
 *
 */

$DB->BeginTrans();

$DB->Execute("
	CREATE SEQUENCE messagetemplates_id_seq;
	CREATE TABLE messagetemplates (
		id integer		DEFAULT nextval('messagetemplates_id_seq'::text) NOT NULL,
		type smallint		NOT NULL,
		name varchar(50)	NOT NULL,
		message	text		DEFAULT '' NOT NULL,
		PRIMARY KEY (id),
		UNIQUE (type, name)
	);
");

$DB->Execute("UPDATE dbinfo SET keyvalue = ? WHERE keytype = ?", array('2013051700', 'dbversion'));

$DB->CommitTrans();

?>