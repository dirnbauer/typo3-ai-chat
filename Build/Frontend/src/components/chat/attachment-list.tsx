import { FileTextIcon, XIcon } from 'lucide-react';
import {
  Attachment,
  AttachmentAction,
  AttachmentActions,
  AttachmentContent,
  AttachmentDescription,
  AttachmentGroup,
  AttachmentMedia,
  AttachmentTitle,
} from '@/components/ui/attachment';
import { formatBytes } from '@/lib/utils';
import type { PromptAttachment } from '@/components/ai/prompt-input';
import type { AttachmentInfo } from '@/state/types';

/**
 * Files that were sent with a message.
 *
 * Read-only: the upload already happened and the FAL uid is in the transcript,
 * so there is nothing here to change.
 */
export function AttachmentList({ attachments }: { attachments: AttachmentInfo[] }) {
  if (attachments.length === 0) {
    return null;
  }

  return (
    <AttachmentGroup>
      {attachments.map((file) => (
        <Attachment key={file.fileUid} size="sm">
          <AttachmentMedia>
            <FileTextIcon aria-hidden="true" />
          </AttachmentMedia>
          <AttachmentContent>
            <AttachmentTitle>{file.fileName}</AttachmentTitle>
            <AttachmentDescription>{formatBytes(file.fileSize)}</AttachmentDescription>
          </AttachmentContent>
        </Attachment>
      ))}
    </AttachmentGroup>
  );
}

/**
 * Files staged in the composer, mid-upload and after.
 *
 * The upload runs the moment a file is picked, because the server needs a FAL
 * uid before the turn starts and doing it at send time would put a 20 MB
 * transfer between pressing Enter and anything happening. The states are
 * therefore visible: uploading shimmers, ready is quiet, an error says what the
 * server said.
 */
export function ComposerAttachments({
  attachments,
  onRemove,
}: {
  attachments: PromptAttachment[];
  onRemove: (id: string) => void;
}) {
  if (attachments.length === 0) {
    return null;
  }

  return (
    <AttachmentGroup>
      {attachments.map((attachment) => (
        <Attachment
          key={attachment.id}
          size="sm"
          state={attachment.status === 'ready' ? 'done' : attachment.status === 'error' ? 'error' : 'uploading'}
        >
          <AttachmentMedia>
            <FileTextIcon aria-hidden="true" />
          </AttachmentMedia>
          <AttachmentContent>
            <AttachmentTitle>{attachment.file.name}</AttachmentTitle>
            <AttachmentDescription>
              {attachment.status === 'error'
                ? (attachment.error ?? 'Upload failed.')
                : formatBytes(attachment.file.size)}
            </AttachmentDescription>
          </AttachmentContent>
          <AttachmentActions>
            <AttachmentAction
              aria-label={`Remove ${attachment.file.name}`}
              onClick={() => onRemove(attachment.id)}
              type="button"
            >
              <XIcon aria-hidden="true" />
            </AttachmentAction>
          </AttachmentActions>
        </Attachment>
      ))}
    </AttachmentGroup>
  );
}
