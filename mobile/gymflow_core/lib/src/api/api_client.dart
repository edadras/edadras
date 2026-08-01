import 'dart:convert';

import 'package:http/http.dart' as http;

import 'api_exception.dart';

/// Talks to the GymFlow AI API. Every request carries the bearer token, the
/// club slug and the chosen language, which is exactly what the backend's
/// tenant and locale middleware expect.
class ApiClient {
  ApiClient({required this.baseUrl, http.Client? httpClient})
      : _http = httpClient ?? http.Client();

  final String baseUrl;
  final http.Client _http;

  String? _token;
  String? _tenant;
  String _locale = 'fa';

  /// Called when the API rejects the token, so the app can sign the user out.
  void Function()? onUnauthenticated;

  String? get token => _token;

  String? get tenant => _tenant;

  String get locale => _locale;

  void configure({String? token, String? tenant, String? locale}) {
    _token = token;
    _tenant = tenant;
    if (locale != null) _locale = locale;
  }

  void setLocale(String locale) => _locale = locale;

  void clearToken() => _token = null;

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Locale': _locale,
        if (_token != null) 'Authorization': 'Bearer $_token',
        if (_tenant != null) 'X-Tenant': _tenant!,
      };

  Future<dynamic> get(String path, {Map<String, dynamic>? query}) =>
      _send('GET', path, query: query);

  Future<dynamic> post(String path, {Map<String, dynamic>? body}) =>
      _send('POST', path, body: body);

  Future<dynamic> put(String path, {Map<String, dynamic>? body}) =>
      _send('PUT', path, body: body);

  Future<dynamic> delete(String path) => _send('DELETE', path);

  /// Fetches raw bytes, for the QR badge SVG and invoice PDFs.
  Future<List<int>> getBytes(String path) async {
    final response = await _http.get(_uri(path, null), headers: _headers);

    if (response.statusCode >= 400) {
      throw _exception(response);
    }

    return response.bodyBytes;
  }

  Future<dynamic> _send(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Map<String, dynamic>? body,
  }) async {
    final request = http.Request(method, _uri(path, query))
      ..headers.addAll(_headers);

    if (body != null) request.body = jsonEncode(body);

    late http.Response response;

    try {
      response = await http.Response.fromStream(await _http.send(request));
    } catch (error) {
      throw ApiException('Could not reach the server. Check your connection.');
    }

    if (response.statusCode == 401) {
      onUnauthenticated?.call();
    }

    if (response.statusCode >= 400) {
      throw _exception(response);
    }

    if (response.body.isEmpty) return null;

    return jsonDecode(utf8.decode(response.bodyBytes));
  }

  Uri _uri(String path, Map<String, dynamic>? query) {
    final cleaned = query?.entries
        .where((entry) => entry.value != null)
        .map((entry) => MapEntry(entry.key, '${entry.value}'));

    return Uri.parse('$baseUrl$path').replace(
      queryParameters: cleaned == null ? null : Map.fromEntries(cleaned),
    );
  }

  ApiException _exception(http.Response response) {
    Map<String, dynamic> payload = const {};

    try {
      final decoded = jsonDecode(utf8.decode(response.bodyBytes));
      if (decoded is Map<String, dynamic>) payload = decoded;
    } catch (_) {
      // A non JSON error body still needs a usable message below.
    }

    final rawErrors = payload['errors'];
    final errors = <String, List<String>>{};

    if (rawErrors is Map) {
      rawErrors.forEach((key, value) {
        errors['$key'] = (value as List).map((item) => '$item').toList();
      });
    }

    return ApiException(
      payload['message'] as String? ?? 'Request failed (${response.statusCode}).',
      statusCode: response.statusCode,
      reason: payload['reason'] as String?,
      errors: errors,
    );
  }
}
