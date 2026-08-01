/// A thread between a member and a coach.
class Conversation {
  const Conversation({
    required this.id,
    this.subject,
    this.memberName,
    this.coachName,
    this.lastMessageAt,
  });

  factory Conversation.fromJson(Map<String, dynamic> json) {
    final member = (json['member'] as Map?)?.cast<String, dynamic>();
    final coach = (json['coach'] as Map?)?.cast<String, dynamic>();

    return Conversation(
      id: json['id'] as int,
      subject: json['subject'] as String?,
      memberName: member == null
          ? null
          : '${member['first_name'] ?? ''} ${member['last_name'] ?? ''}'.trim(),
      coachName: coach == null
          ? null
          : '${coach['first_name'] ?? ''} ${coach['last_name'] ?? ''}'.trim(),
      lastMessageAt: json['last_message_at'] == null
          ? null
          : DateTime.tryParse('${json['last_message_at']}'),
    );
  }

  final int id;
  final String? subject;
  final String? memberName;
  final String? coachName;
  final DateTime? lastMessageAt;

  /// Whoever is on the other end of the thread, from this app's point of view.
  String titleFor({required bool viewerIsMember}) {
    final other = viewerIsMember ? coachName : memberName;

    return (other == null || other.isEmpty) ? (subject ?? '—') : other;
  }
}

class ChatMessage {
  const ChatMessage({
    required this.id,
    required this.senderType,
    required this.senderId,
    required this.body,
    required this.createdAt,
    this.readAt,
  });

  factory ChatMessage.fromJson(Map<String, dynamic> json) => ChatMessage(
        id: json['id'] as int,
        senderType: json['sender_type'] as String? ?? 'staff',
        senderId: (json['sender_id'] as num?)?.toInt() ?? 0,
        body: json['body'] as String? ?? '',
        createdAt: DateTime.parse('${json['created_at']}'),
        readAt: json['read_at'] == null ? null : DateTime.tryParse('${json['read_at']}'),
      );

  final int id;
  final String senderType;
  final int senderId;
  final String body;
  final DateTime createdAt;
  final DateTime? readAt;

  bool get isRead => readAt != null;

  /// A message is "mine" when this device's user sent it.
  bool sentBy(int userId) => senderId == userId;
}

/// One of the club's notifications, as stored for the member's app.
class AppNotification {
  const AppNotification({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    required this.createdAt,
    this.readAt,
    this.payload = const {},
  });

  factory AppNotification.fromJson(Map<String, dynamic> json) {
    final data = ((json['data'] as Map?) ?? const {}).cast<String, dynamic>();

    return AppNotification(
      id: '${json['id']}',
      type: data['type'] as String? ?? 'general',
      title: data['title'] as String? ?? '',
      body: data['body'] as String? ?? '',
      payload: data,
      createdAt: DateTime.parse('${json['created_at']}'),
      readAt: json['read_at'] == null ? null : DateTime.tryParse('${json['read_at']}'),
    );
  }

  final String id;
  final String type;
  final String title;
  final String body;
  final Map<String, dynamic> payload;
  final DateTime createdAt;
  final DateTime? readAt;

  bool get isUnread => readAt == null;
}
