// ignore: deprecated_member_use
import 'dart:html' as html;

void downloadAndroidApp() {
  html.AnchorElement(href: 'downloads/grandview-school.apk')
    ..download = 'grandview-school.apk'
    ..click();
}

void installIosApp() {
  const manifestUrl =
      'https://YOUR-DOMAIN.example/downloads/grandview-school.plist';
  final installUrl =
      'itms-services://?action=download-manifest&url=${Uri.encodeComponent(manifestUrl)}';
  html.window.location.href = installUrl;
}
