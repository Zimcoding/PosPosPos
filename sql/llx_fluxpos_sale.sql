-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses.


-- One row per sale paid on a terminal while it was offline. The unique sale id made by
-- the terminal guarantees that a sale sent twice is booked only once.
CREATE TABLE llx_fluxpos_sale(
    rowid integer AUTO_INCREMENT PRIMARY KEY,
    entity integer DEFAULT 1 NOT NULL,
    sale_uid varchar(64) NOT NULL,
    terminal varchar(16),
    place varchar(64),
    fk_facture integer,
    date_sale datetime,
    date_creation datetime NOT NULL,
    fk_user_creat integer,
    status smallint DEFAULT 0 NOT NULL,
    note varchar(255)
) ENGINE=innodb;
