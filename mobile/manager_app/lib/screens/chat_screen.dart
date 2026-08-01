import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';
import 'package:intl/intl.dart';

/// The club's side of the member ↔ coach chat.
class ConversationsScreen extends StatefulWidget {
  const ConversationsScreen({super.key});

  @override
  State<ConversationsScreen> createState() => _ConversationsScreenState();
}

class _ConversationsScreenState extends State<ConversationsScreen> {
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

    return DetailScaffold(
      title: t.t('nav.chat', 'Chat'),
      child: _loading
          ? const LoadingState()
          : RefreshIndicator(
              onRefresh: _load,
              color: AppTheme.brand,
              backgroundColor: AppTheme.ink800,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  if (_conversations.isEmpty)
                    EmptyState(
                      icon: Icons.forum_outlined,
                      message: t.t('chat.empty', 'No conversations yet.'),
                    ),
                  for (final conversation in _conversations)
                    GlassCard(
                      margin: const EdgeInsets.only(bottom: 10),
                      padding: const EdgeInsets.all(14),
                      onTap: () => Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) => ChatThreadScreen(
                            conversation: conversation,
                            viewerIsMember: false,
                          ),
                        ),
                      ),
                      child: Row(
                        children: [
                          const CircleAvatar(
                            radius: 20,
                            backgroundColor: Color(0x1AFFFFFF),
                            child: Icon(Icons.person_outline, color: AppTheme.ink200),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  conversation.titleFor(viewerIsMember: false),
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                if (conversation.lastMessageAt != null)
                                  Text(
                                    DateFormat.MMMd()
                                        .add_Hm()
                                        .format(conversation.lastMessageAt!),
                                    style: Theme.of(context).textTheme.labelSmall,
                                  ),
                              ],
                            ),
                          ),
                          const Icon(Icons.chevron_right, color: AppTheme.ink400),
                        ],
                      ),
                    ),
                ],
              ),
            ),
    );
  }
}

/// One thread. Shared by both apps — only the "who am I" flag differs.
class ChatThreadScreen extends StatefulWidget {
  const ChatThreadScreen({
    super.key,
    required this.conversation,
    required this.viewerIsMember,
  });

  final Conversation conversation;
  final bool viewerIsMember;

  @override
  State<ChatThreadScreen> createState() => _ChatThreadScreenState();
}

class _ChatThreadScreenState extends State<ChatThreadScreen> {
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
      _scrollToEnd();
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

  void _scrollToEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scrollController.hasClients) return;

      _scrollController.jumpTo(_scrollController.position.maxScrollExtent);
    });
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);
    final userId = (SessionScope.of(context).user?['id'] as num?)?.toInt() ?? 0;

    return DetailScaffold(
      title: widget.conversation.titleFor(viewerIsMember: widget.viewerIsMember),
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
                        itemBuilder: (context, index) => _MessageBubble(
                          message: _messages[index],
                          mine: _messages[index].sentBy(userId),
                        ),
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
                    decoration: InputDecoration(
                      hintText: t.t('chat.message', 'Message…'),
                    ),
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

class _MessageBubble extends StatelessWidget {
  const _MessageBubble({required this.message, required this.mine});

  final ChatMessage message;
  final bool mine;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: mine ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart,
      child: Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * 0.75),
        decoration: BoxDecoration(
          color: mine ? AppTheme.brand : Colors.white.withValues(alpha: 0.08),
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
                color: mine ? AppTheme.ink900.withValues(alpha: 0.6) : AppTheme.ink400,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
