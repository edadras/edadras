import 'member.dart';

/// One gate pass: the entry, and the exit when it happens.
class Attendance {
  const Attendance({
    required this.id,
    required this.checkedInAt,
    this.checkedOutAt,
    this.method = 'qr',
    this.member,
  });

  factory Attendance.fromJson(Map<String, dynamic> json) => Attendance(
        id: json['id'] as int,
        checkedInAt: DateTime.parse('${json['checked_in_at']}'),
        checkedOutAt: json['checked_out_at'] == null
            ? null
            : DateTime.tryParse('${json['checked_out_at']}'),
        method: json['method'] as String? ?? 'qr',
        member: json['member'] == null
            ? null
            : Member.fromJson((json['member'] as Map).cast<String, dynamic>()),
      );

  final int id;
  final DateTime checkedInAt;
  final DateTime? checkedOutAt;
  final String method;
  final Member? member;

  bool get isInside => checkedOutAt == null;

  Duration? get duration =>
      checkedOutAt == null ? null : checkedOutAt!.difference(checkedInAt);
}
