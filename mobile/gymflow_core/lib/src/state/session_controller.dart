import 'package:flutter/widgets.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../models/club.dart';

/// Who is signed in, to which club, and what they are allowed to do. The
/// token survives a restart so the app opens straight into its home screen.
class SessionController extends ChangeNotifier {
  SessionController(this.api) {
    api.onUnauthenticated = signOutLocally;
  }

  static const _tokenKey = 'gymflow.token';
  static const _tenantKey = 'gymflow.tenant';
  static const _localeKey = 'gymflow.locale';

  final ApiClient api;

  Map<String, dynamic>? _user;
  Club? _club;
  List<String> _permissions = const [];
  bool _restoring = true;

  Map<String, dynamic>? get user => _user;

  Club? get club => _club;

  bool get isAuthenticated => _user != null;

  bool get isRestoring => _restoring;

  bool get isSuperAdmin => _user?['is_super_admin'] == true;

  String get displayName => '${_user?['name'] ?? ''}';

  /// Mirrors the API's wildcard rules so a screen is never offered to
  /// someone the backend would refuse.
  bool can(String permission) {
    if (_user == null) return false;
    if (isSuperAdmin || _permissions.contains('*')) return true;
    if (_permissions.contains(permission)) return true;

    return _permissions.contains('${permission.split('.').first}.*');
  }

  /// Reads the stored token and asks the API who it belongs to.
  Future<void> restore() async {
    final preferences = await SharedPreferences.getInstance();

    api.configure(
      token: preferences.getString(_tokenKey),
      tenant: preferences.getString(_tenantKey),
      locale: preferences.getString(_localeKey) ?? 'fa',
    );

    if (api.token != null) {
      try {
        final response = await api.get('/auth/me');
        _apply(response);
      } catch (_) {
        await _clearStorage();
      }
    }

    _restoring = false;
    notifyListeners();
  }

  Future<void> signIn({
    required String tenant,
    required String email,
    required String password,
  }) async {
    api.configure(token: null, tenant: tenant, locale: api.locale);

    final response = await api.post('/auth/login', body: {
      'email': email,
      'password': password,
      'device_name': 'mobile',
    });

    await _persist(response['token'] as String, tenant);
    _apply(response);
  }

  Future<void> signInAsMember({
    required String tenant,
    required String phone,
    required String password,
  }) async {
    api.configure(token: null, tenant: tenant, locale: api.locale);

    final response = await api.post('/auth/member-login', body: {
      'phone': phone,
      'password': password,
    });

    await _persist(response['token'] as String, tenant);
    _apply(response);
  }

  Future<void> signOut() async {
    try {
      await api.post('/auth/logout');
    } catch (_) {
      // The token is being discarded either way.
    }

    await _clearStorage();
    signOutLocally();
  }

  void signOutLocally() {
    api.clearToken();
    _user = null;
    _club = null;
    _permissions = const [];
    notifyListeners();
  }

  Future<void> setLocale(String locale) async {
    final preferences = await SharedPreferences.getInstance();

    await preferences.setString(_localeKey, locale);
    api.setLocale(locale);
    notifyListeners();
  }

  void _apply(Map<String, dynamic> response) {
    _user = (response['user'] as Map?)?.cast<String, dynamic>();
    _club = response['club'] == null
        ? null
        : Club.fromJson((response['club'] as Map).cast<String, dynamic>());
    _permissions =
        ((response['permissions'] as List?) ?? const []).map((item) => '$item').toList();

    notifyListeners();
  }

  Future<void> _persist(String token, String tenant) async {
    final preferences = await SharedPreferences.getInstance();

    await preferences.setString(_tokenKey, token);
    await preferences.setString(_tenantKey, tenant);

    api.configure(token: token, tenant: tenant, locale: api.locale);
  }

  Future<void> _clearStorage() async {
    final preferences = await SharedPreferences.getInstance();

    await preferences.remove(_tokenKey);
  }
}

/// Hands the session to the widget tree.
class SessionScope extends InheritedNotifier<SessionController> {
  const SessionScope({
    super.key,
    required SessionController session,
    required super.child,
  }) : super(notifier: session);

  static SessionController of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<SessionScope>();

    assert(scope != null, 'Wrap the app in a SessionScope.');

    return scope!.notifier!;
  }
}
