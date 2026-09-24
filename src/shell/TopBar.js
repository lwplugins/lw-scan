/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { displayShortcut } from '@wordpress/keycodes';

/**
 * Main column header: section title left; on form tabs Discard / Save right.
 *
 * @param {Object}      props
 * @param {string}      props.title Current section title.
 * @param {Object|null} props.store Save store of the tab ({ hasEdits, isSaving, save, discard }), or null.
 */
export default function TopBar( { title, store } ) {
	return (
		<header className="lw-admin-topbar">
			<h1 className="lw-admin-topbar__title">{ title }</h1>
			{ store && (
				<div className="lw-admin-topbar__actions">
					{ store.hasEdits && (
						<>
							<span className="lw-admin-topbar__dirty">
								{ __( 'Unsaved changes', 'lw-scan' ) }
							</span>
							<Button
								__next40pxDefaultSize
								variant="tertiary"
								onClick={ store.discard }
							>
								{ __( 'Discard', 'lw-scan' ) }
							</Button>
						</>
					) }
					<Button
						__next40pxDefaultSize
						variant="primary"
						className="lw-admin-save"
						isBusy={ store.isSaving }
						disabled={ ! store.hasEdits || store.isSaving }
						accessibleWhenDisabled
						onClick={ () => store.save() }
					>
						<kbd>{ displayShortcut.primary( 's' ) }</kbd>
						{ __( 'Save changes', 'lw-scan' ) }
					</Button>
				</div>
			) }
		</header>
	);
}
