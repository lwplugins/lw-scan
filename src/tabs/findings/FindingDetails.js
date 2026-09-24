/**
 * WordPress dependencies
 */
import { ExternalLink, Modal } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { CopyButton } from '../../components/CopyField';

/**
 * Match excerpt, why it was flagged, what to do (and the vulnerability record).
 *
 * @param {Object}   props
 * @param {Object}   props.finding Finding view.
 * @param {Function} props.onClose Close.
 */
export default function FindingDetails( { finding, onClose } ) {
	const why = [ finding.reason, finding.signal_reasons?.join( ', ' ) ]
		.filter( Boolean )
		.join( ' · ' );

	return (
		<Modal
			title={ finding.title }
			size="large"
			onRequestClose={ onClose }
			className="lw-admin-detail-modal"
		>
			<div className="lw-admin-detail">
				{ finding.excerpt && (
					<div className="lw-admin-stack">
						<span className="lw-admin-label">
							{ sprintf(
								/* translators: %d: line number. */
								__( 'Match · line %d', 'lw-scan' ),
								finding.excerpt_line || 0
							) }
						</span>
						<pre className="lw-admin-excerpt">
							<mark>{ finding.excerpt }</mark>
						</pre>
					</div>
				) }
				<div className="lw-admin-stack">
					{ why && (
						<>
							<span className="lw-admin-label">
								{ finding.severity === 'alert'
									? __( 'Why it is an alert', 'lw-scan' )
									: __( 'Why it is flagged', 'lw-scan' ) }
							</span>
							<p>{ why }</p>
						</>
					) }
					<span className="lw-admin-label">
						{ __( 'What to do', 'lw-scan' ) }
					</span>
					<p>{ finding.advice }</p>
					{ finding.can_copy_path && (
						<CopyButton
							text={ finding.locator }
							label={ __( 'Copy path', 'lw-scan' ) }
						/>
					) }
				</div>
			</div>
			{ finding.vuln && (
				<p className="lw-admin-hint lw-admin-detail__vuln">
					{ finding.vuln.title }
					{ finding.vuln.reference && (
						<>
							{ ' · ' }
							<ExternalLink href={ finding.vuln.reference }>
								{ __( 'Record and license', 'lw-scan' ) }
							</ExternalLink>
						</>
					) }
					{ finding.vuln.notice && <> · { finding.vuln.notice }</> }
				</p>
			) }
		</Modal>
	);
}
