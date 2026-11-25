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

-- 1.8.0: an offline sale that needs checking (paid twice, total differs) is marked.
-- Safe to run again: "column already exists" is ignored at module activation.
ALTER TABLE llx_fluxpos_sale ADD COLUMN status smallint DEFAULT 0 NOT NULL;
ALTER TABLE llx_fluxpos_sale ADD COLUMN note varchar(255);
