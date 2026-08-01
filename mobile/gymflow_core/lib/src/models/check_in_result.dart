import 'member.dart';

/// What the scanner screen shows after a badge is read: the green card, or
/// the red one with the reason the gate stayed shut.
class CheckInResult {
  const CheckInResult({
    required this.allowed,
    required this.message,
    this.reason,
    this.member,
    this.remainingSessions,
    this.daysRemaining,
  });

  factory CheckInResult.fromJson(Map<String, dynamic> json, {bool allowed = true}) =>
      CheckInResult(
        allowed: json['allowed'] as bool? ?? allowed,
        message: json['message'] as String? ?? '',
        reason: json['reason'] as String?,
        member: json['member'] == null
            ? null
            : Member.fromJson((json['member'] as Map).cast<String, dynamic>()),
        remainingSessions: (json['remaining_sessions'] as num?)?.toInt(),
        daysRemaining: (json['days_remaining'] as num?)?.toInt(),
      );

  factory CheckInResult.refused(String message, {String? reason}) =>
      CheckInResult(allowed: false, message: message, reason: reason);

  final bool allowed;
  final String message;
  final String? reason;
  final Member? member;
  final int? remainingSessions;
  final int? daysRemaining;

  /// A refusal the desk can fix by selling a renewal on the spot.
  bool get isRenewable =>
      reason == 'expired' || reason == 'no_sessions_left' || reason == 'no_membership';
}
