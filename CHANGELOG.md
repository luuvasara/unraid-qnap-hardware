# Changelog

Copied by the build from the <CHANGES> block of qnap-hardware.plg; edit the .plg, not this file.

## 2026.11.08a
- First version. Utilities, QNAP Hardware, for a QNAP TL-D1600S on its QXP-1600eS-A1164 card.
- Overview: a live front view of the enclosure as its bays are labelled (HDD1-HDD12, SSD1-SSD4), each drive with its Unraid slot, temperature and state as Unraid last read them (no drive is woken), redrawn every 10 seconds; one card per unit (drives, hottest drive, health, card link, cabling); the bay map.
- Bay labels on the Main page: "HDD3" after each drive's name (setting on the Overview tab, on by default).
- History: drive temperatures per bay over 1, 6 or 24 hours, from one sample a minute kept in RAM (setting on the Overview tab; off = nothing runs in the background), with the same numbers as a table.
- Hardware: the card's PCIe links and error counters, the controllers' capabilities, each drive's SATA link, firmware and SMART counters; known issues checked against this server; what firmware can and cannot be read.
- Fans and LEDs: the enclosure runs them itself and the server has no way to reach them; the Hardware tab says so and why.
- Report: a read-only hardware report, anonymised on the server, to copy, download or open as an issue.
