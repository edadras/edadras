/// A failed API call. `reason` carries the machine readable code the backend
/// sends for refusals at the gate, so the scanner screen can react to the
/// cause rather than parse the message.
class ApiException implements Exception {
  ApiException(
    this.message, {
    this.statusCode,
    this.reason,
    this.errors = const {},
  });

  final String message;
  final int? statusCode;
  final String? reason;
  final Map<String, List<String>> errors;

  bool get isUnauthenticated => statusCode == 401;

  bool get isValidation => statusCode == 422;

  /// The first field level error, which is what a form wants to show.
  String? get firstFieldError =>
      errors.values.where((list) => list.isNotEmpty).map((list) => list.first).firstOrNull;

  @override
  String toString() => message;
}

extension _FirstOrNull<E> on Iterable<E> {
  E? get firstOrNull => isEmpty ? null : first;
}
