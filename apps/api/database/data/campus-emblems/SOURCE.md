# Campus emblem source

The main image set comes from the network-origin
[`xioajiumi/Chinese_Universities`](https://github.com/xioajiumi/Chinese_Universities)
repository, fixed at commit
`e088fddc329a6f0148ca72fd3b4d0fe069f13e3a`. The imported archive is
`logo.zip`, distributed by that repository under the MIT License (Copyright
2022 xioajiumi). The complete upstream license is preserved in
`LICENSE.upstream`.

Only archive filenames that exactly match a school name in the bundled 2026
Ministry of Education institution directory are included. Each matched image
is converted to a centered, transparent 192 x 192 PNG and named with its
official school code. `manifest.json` records the deterministic mapping and
SHA-256 digest for every file.

The archive entry for 吕梁学院 contained HTML rather than an image. It is
replaced with the emblem published on the
[吕梁学院校长办公室 website](https://yzbgs.llu.edu.cn/info/1267/1704.htm);
the exact image URL is recorded on that manifest item.

School emblems may be trademarks or copyrighted works of their respective
institutions. The upstream MIT license applies to the upstream data package
and code; it does not transfer ownership of, license, or imply official
endorsement by any school. These copies are used only for campus
identification and should be replaced or removed if an institution requests
it.
