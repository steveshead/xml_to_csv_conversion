# XML to CSV Converter

A single PHP script that converts XML files to CSV without needing to know the
file's structure. It finds the repeating record elements on its own and writes
one CSV per record type.

## Requirements

- PHP 7.1 or later, run from the command line
- The `XMLReader` extension, which ships with PHP and is enabled by default

## Usage

```sh
php xml_to_csv_conversion.php                   # convert every *.xml next to the script
php xml_to_csv_conversion.php <input.xml>       # convert one file
php xml_to_csv_conversion.php <input_dir>       # convert every *.xml in a directory
php xml_to_csv_conversion.php <input> <out_dir> # write the CSVs to out_dir instead
```

Each CSV is named `<input>.<tag>.csv` and is written next to its input file
unless you pass `<out_dir>`. If a CSV with that name already exists, it is
overwritten.

## Example

Given `library.xml`:

```xml
<library>
  <books>
    <book><title>Dune</title><author>Frank Herbert</author><year>1965</year></book>
    <book><title>Emma</title><author>Jane Austen</author></book>
  </books>
  <members>
    <member><name>Ana</name><joined>2024-03-01</joined></member>
    <member><name>Ben</name><joined>2025-07-19</joined></member>
  </members>
</library>
```

the script writes two files.

`library.book.csv`:

```csv
title,author,year
Dune,"Frank Herbert",1965
Emma,"Jane Austen",
```

`library.member.csv`:

```csv
name,joined
Ana,2024-03-01
Ben,2025-07-19
```

## How it works

- **Record detection:** a tag counts as a record if it appears at least twice
  as a direct child of a single parent element, like `<book>` inside `<books>`.
  When repeating elements are nested, only the innermost level is used. A
  repeating container that holds other records doesn't get a CSV of its own.
- **Columns:** each record's direct child elements become its columns. The
  header lists every field seen in any record of that type, so a record that
  leaves out an optional field gets an empty cell in that column.
- **Streaming:** the file is read with `XMLReader` in passes rather than loaded
  into memory, so large files are fine.

## Limitations

- Attributes are ignored. Only element text is exported.
- Records are flattened to one level. Text inside deeper nested elements is
  joined into the value of the direct child that contains it.
- If a record contains the same child tag more than once, the values are
  joined together in a single cell.

## License

GPL-3.0. See [LICENSE](LICENSE).
