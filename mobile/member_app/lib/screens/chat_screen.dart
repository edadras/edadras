import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';
import 'package:intl/intl.dart';

/// The member's side of the chat with their coach.
class MemberChatScreen extends StatefulWidget {
  const MemberChatScreen({super.key});

  @override
  State<MemberChatScreen> createState() => _MemberChatScreenState();
}

class _MemberChatScreenState extends State<MemberChatScreen> {
  List<Conversation> _conversations = const [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);

    try {
      final response = await SessionScope.of(context).api.get('/conversations');

      setState(() {
        _conversations = ((response as Map)['data'] as List)
            .cast<Map>()
            .map((row) => Conversation.fromJson(row.cast<String, dynamic>()))
            .toList();
      });
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    // With a single coach thread there is no list worth showing — open it.
    if (!_loading && _conversations.length == 1) {
      return MemberChatThread(conversation: _conversations.first);
    }

    return DetailScaffold(
      title: t.t('nav.chat', 'Chat'),
      child: _loading
          ? const LoadingState()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (_conversations.isEmpty)
                  EmptyState(
                    icon: Icons.forum_outlined,
                    message: t.t('chat.member_empty', 'Your coach has not started a chat yet.'),
                  ),
                for (final conversation in _conversations)
                  GlassCard(
                    margin: const EdgeInsets.only(bottom: 10),
                    padding: const EdgeInsets.all(14),
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => MemberChatThread(conversation: conversation),
                      ),
                    ),
                    child: Row(
                      children: [
                        const CircleAvatar(
                          radius: 20,
                          backgroundColor: Color(0x1AFFFFFF),
                          child: Icon(Icons.sports_outlined, color: AppTheme.ink200),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Text(
                            conversation.titleFor(viewerIsMember: true),
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                        const Icon(Icons.chevron_right, color: AppTheme.ink400),
                      ],
                    ),
                  ),
              ],
            ),
    );
  }
}

class MemberChatThread extends StatefulWidget {
  const MemberChatThread({super.key, required this.conversation});

  final Conversation conversation;

  @override
  State<MemberChatThread> createState() => _MemberChatThreadState();
}

class _MemberChatThreadState extends State<MemberChatThread> {
  final _controller = TextEditingController();
  final _scrollController = ScrollController();

  List<ChatMessage> _messages = const [];
  bool _loading = true;
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _controller.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final response = await SessionScope.of(context)
          .api
          .get('/conversations/${widget.conversation.id}/messages', query: {'per_page': 100});

      setState(() {
        // The API returns newest first; a thread reads oldest first.
        _messages = ((response as Map)['data'] as List)
            .cast<Map>()
            .map((row) => ChatMessage.fromJson(row.cast<String, dynamic>()))
            .toList()
            .reversed
            .toList();
      });
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_scrollController.hasClients) {
          _scrollController.jumpTo(_scrollController.position.maxScrollExtent);
        }
      });
    }
  }

  Future<void> _send() async {
    final body = _controller.text.trim();

    if (body.isEmpty || _sending) return;

    setState(() => _sending = true);
    _controller.clear();

    try {
      await SessionScope.of(context)
          .api
          .post('/conversations/${widget.conversation.id}/messages', body: {'body': body});
      await _load();
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);
    final userId = (SessionScope.of(context).user?['id'] as num?)?.toInt() ?? 0;

    return DetailScaffold(
      title: widget.conversation.titleFor(viewerIsMember: true),
      child: Column(
        children: [
          Expanded(
            child: _loading
                ? const LoadingState()
                : _messages.isEmpty
                    ? EmptyState(
                        icon: Icons.chat_bubble_outline,
                        message: t.t('chat.say_hello', 'Say hello.'),
                      )
                    : ListView.builder(
                        controller: _scrollController,
                        padding: const EdgeInsets.all(16),
                        itemCount: _messages.length,
                        itemBuilder: (context, index) {
                          final message = _messages[index];
                          final mine = message.sentBy(userId);

                          return Align(
                            alignment: mine
                                ? AlignmentDirectional.centerEnd
                                : AlignmentDirectional.centerStart,
                            child: Container(
                              margin: const EdgeInsets.only(bottom: 10),
                              padding: const EdgeInsets.symmetric(
                                horizontal: 14,
                                vertical: 10,
                              ),
                              constraints: BoxConstraints(
                                maxWidth: MediaQuery.sizeOf(context).width * 0.75,
                              ),
                              decoration: BoxDecoration(
                                color: mine
                                    ? AppTheme.brand
                                    : Colors.white.withValues(alpha: 0.08),
                                borderRadius: BorderRadius.circular(16),
                              ),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    message.body,
                                    style: TextStyle(
                                      color: mine ? AppTheme.ink900 : Colors.white,
                                      height: 1.4,
                                    ),
                                  ),
                                  const SizedBox(height: 3),
                                  Text(
                                    DateFormat.Hm().format(message.createdAt),
                                    style: TextStyle(
                                      fontSize: 10,
                                      color: mine
                                          ? AppTheme.ink900.withValues(alpha: 0.6)
                                          : AppTheme.ink400,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          );
                        },
                      ),
          ),

          Padding(
            padding: EdgeInsets.only(
              left: 12,
              right: 12,
              bottom: 12 + MediaQuery.viewInsetsOf(context).bottom,
            ),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _controller,
                    onSubmitted: (_) => _send(),
                    decoration:
                        InputDecoration(hintText: t.t('chat.message', 'Message…')),
                  ),
                ),
                const SizedBox(width: 8),
                IconButton.filled(
                  onPressed: _sending ? null : _send,
                  icon: const Icon(Icons.send_rounded),
                  style: IconButton.styleFrom(
                    backgroundColor: AppTheme.brand,
                    foregroundColor: AppTheme.ink900,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
