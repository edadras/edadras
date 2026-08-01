import 'package:flutter/widgets.dart';

import '../api/api_client.dart';

/// The app's string table. It is downloaded from the API rather than baked
/// into the binary, so a wording change ships without a store release.
class Translations extends ChangeNotifier {
  Translations(this._api);

  final ApiClient _api;

  Map<String, dynamic> _lines = const {};
  Map<String, dynamic> _locales = const {};
  String _locale = 'fa';
  TextDirection _direction = TextDirection.rtl;

  String get locale => _locale;

  TextDirection get direction => _direction;

  Map<String, dynamic> get locales => _locales;

  bool get isReady => _lines.isNotEmpty;

  Future<void> loadLocales() async {
    final response = await _api.get('/locales');

    _locales = (response['locales'] as Map).cast<String, dynamic>();
    notifyListeners();
  }

  Future<void> load(String locale) async {
    final response = await _api.get('/translations/$locale');

    _lines = (response['lines'] as Map).cast<String, dynamic>();
    _locale = locale;
    _direction = response['direction'] == 'rtl' ? TextDirection.rtl : TextDirection.ltr;

    _api.setLocale(locale);
    notifyListeners();
  }

  /// `t('checkin.allowed')`. Shared strings live in their own group; the
  /// app's own labels sit under "panel", which is searched second.
  String t(String key, [String? fallback]) {
    final path = key.split('.');

    return _resolve(_lines, path) ?? _resolve(_lines['panel'], path) ?? fallback ?? key;
  }

  String? _resolve(dynamic root, List<String> path) {
    dynamic node = root;

    for (final part in path) {
      if (node is! Map) return null;
      node = node[part];
    }

    return node is String ? node : null;
  }
}

/// Hands the string table to the widget tree.
class TranslationsScope extends InheritedNotifier<Translations> {
  const TranslationsScope({
    super.key,
    required Translations translations,
    required super.child,
  }) : super(notifier: translations);

  static Translations of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<TranslationsScope>();

    assert(scope != null, 'Wrap the app in a TranslationsScope.');

    return scope!.notifier!;
  }
}
